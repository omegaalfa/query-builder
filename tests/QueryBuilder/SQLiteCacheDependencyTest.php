<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\Interfaces\CacheInterface;
use Omegaalfa\QueryBuilder\Interfaces\ConnectionInterface;
use Omegaalfa\QueryBuilder\QueryBuilder;
use PDO;
use PHPUnit\Framework\TestCase;

final class SQLiteCacheDependencyTest extends TestCase
{
    private PDO $pdo;

    private ConnectionInterface $connection;

    private TagAwareSQLiteCache $cache;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }

        $this->pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE other_items (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
        $this->cache = new TagAwareSQLiteCache();
        $this->connection = $this->connectionFor($this->pdo);
    }

    public function testCachedSelectIsStoredWithTheMainTableTagAndCanHit(): void
    {
        $this->pdo->exec("INSERT INTO items VALUES (1, 'initial')");
        $builder = $this->builder();

        $first = $builder->select('items', ['value'])->where('id', '=', 1)->cache(300)->execute();
        $firstTags = $this->cache->tagsForLastSet;
        $second = $builder->select('items', ['value'])->where('id', '=', 1)->cache(300)->execute();

        self::assertSame([['value' => 'initial']], $this->rows($first->data));
        self::assertSame([['value' => 'initial']], $second->data);
        self::assertSame($firstTags, $this->cache->tagsForLastSet);
        self::assertCount(2, $firstTags);
        self::assertMatchesRegularExpression('/^qb:([a-f0-9]{32}):table:' . hash('xxh128', 'items') . '$/', $firstTags[0]);
        self::assertSame('qb:' . substr($firstTags[0], 3, 32) . ':database', $firstTags[1]);
        self::assertSame(1, $this->cache->hits);
    }

    public function testInsertUpdateAndDeleteInvalidateCachedSelects(): void
    {
        $this->pdo->exec("INSERT INTO items VALUES (1, 'initial')");

        $this->readAllItems();
        $this->builder()->insert('items', ['id' => 2, 'value' => 'inserted'])->execute();
        self::assertSame(
            [['value' => 'initial'], ['value' => 'inserted']],
            $this->readAllItems()
        );

        $this->readItem();
        $this->builder()->update('items', ['value' => 'updated'])->where('id', '=', 1)->execute();
        self::assertSame([['value' => 'updated']], $this->readItem());

        $this->readItem();
        $this->builder()->delete('items')->where('id', '=', 1)->execute();
        self::assertSame([], $this->readItem());

        self::assertSame(3, $this->cache->invalidations);
    }

    public function testReusingBuilderDoesNotCarryThePreviousTableDependency(): void
    {
        $this->pdo->exec("INSERT INTO items VALUES (1, 'one')");
        $this->pdo->exec("INSERT INTO other_items VALUES (1, 'other')");
        $builder = $this->builder();

        $builder->select('items', ['value'])->cache(300)->execute();
        $builder->select('other_items', ['value'])->cache(300)->execute();

        $tags = $this->cache->tagsForLastSet;
        self::assertCount(2, $tags);
        self::assertMatchesRegularExpression('/^qb:([a-f0-9]{32}):table:' . hash('xxh128', 'other_items') . '$/', $tags[0]);
        self::assertSame('qb:' . substr($tags[0], 3, 32) . ':database', $tags[1]);
        self::assertNotSame('qb:' . substr($tags[0], 3, 32) . ':table:' . hash('xxh128', 'items'), $tags[0]);
        self::assertSame(2, $this->cache->sets);
    }

    public function testWritesRemainOutsideTheResultCache(): void
    {
        $builder = $this->builder();
        $builder->insert('items', ['id' => 1, 'value' => 'written'])->cache(300)->execute();

        self::assertSame(0, $this->cache->sets);
        self::assertSame(0, $this->cache->lookups);
        self::assertSame('written', $this->pdo->query('SELECT value FROM items')->fetchColumn());
    }

    public function testIndependentMemoryDatabasesRemainIsolated(): void
    {
        $firstPdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $secondPdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach ([[$firstPdo, 'first'], [$secondPdo, 'second']] as [$pdo, $value]) {
            $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
            $statement = $pdo->prepare('INSERT INTO items VALUES (1, ?)');
            $statement->execute([$value]);
        }

        $first = $this->builderFor($firstPdo)->select('items', ['value'])->cache(300)->execute();
        $second = $this->builderFor($secondPdo)->select('items', ['value'])->cache(300)->execute();

        self::assertSame([['value' => 'first']], $this->rows($first->data));
        self::assertSame([['value' => 'second']], $this->rows($second->data));
        self::assertSame(0, $this->cache->hits);
        self::assertSame(2, $this->cache->sets);
    }

    private function readItem(): array
    {
        $result = $this->builder()->select('items', ['value'])->where('id', '=', 1)->cache(300)->execute();
        return $this->rows($result->data);
    }

    private function readAllItems(): array
    {
        $result = $this->builder()->select('items', ['value'])->orderBy('id')->cache(300)->execute();
        return $this->rows($result->data);
    }

    private function builder(): QueryBuilder
    {
        return new QueryBuilder($this->connection, null, $this->cache);
    }

    private function builderFor(PDO $pdo): QueryBuilder
    {
        return new QueryBuilder($this->connectionFor($pdo), null, $this->cache);
    }

    private function connectionFor(PDO $pdo): ConnectionInterface
    {
        return new class($pdo) implements ConnectionInterface {
            public function __construct(private PDO $pdo) {}
            public function connect(): void {}
            public function pdo(bool $bufferedQuery = true): PDO { return $this->pdo; }
            public function disconnect(): void {}
            public function transaction(callable $callback): mixed { return $callback($this->pdo); }
            public function getDriver(): string { return 'sqlite'; }
        };
    }

    private function rows(iterable $rows): array
    {
        return is_array($rows) ? $rows : iterator_to_array($rows, false);
    }
}

final class TagAwareSQLiteCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $entries = [];

    /** @var array<string, list<string>> */
    private array $entryTags = [];

    /** @var list<string> */
    public array $tagsForLastSet = [];

    public int $hits = 0;
    public int $lookups = 0;
    public int $sets = 0;
    public int $invalidations = 0;

    public function has(string $key): bool
    {
        $this->lookups++;
        return array_key_exists($key, $this->entries);
    }

    public function get(string $key): mixed
    {
        if (array_key_exists($key, $this->entries)) {
            $this->hits++;
        }
        return $this->entries[$key] ?? null;
    }

    public function set(string $key, mixed $value, int $ttl = 3600): void
    {
        $this->store($key, $value, []);
    }

    /** @param list<string> $tags */
    public function setWithTags(string $key, mixed $value, int $ttl, array $tags): void
    {
        $this->store($key, $value, $tags);
    }

    /** @param list<string> $tags */
    public function flushTags(array $tags): bool
    {
        $this->invalidations++;
        foreach ($this->entryTags as $key => $entryTags) {
            if (array_intersect($tags, $entryTags) !== []) {
                unset($this->entries[$key], $this->entryTags[$key]);
            }
        }
        return true;
    }

    public function delete(string $key): void { unset($this->entries[$key], $this->entryTags[$key]); }
    public function deletePattern(string $pattern): bool { return true; }
    public function clear(): bool { $this->entries = []; $this->entryTags = []; return true; }
    public function getMultiple(array $keys): array { return array_intersect_key($this->entries, array_flip($keys)); }

    /** @param list<string> $tags */
    private function store(string $key, mixed $value, array $tags): void
    {
        $this->sets++;
        $this->entries[$key] = $value;
        $this->entryTags[$key] = $tags;
        $this->tagsForLastSet = $tags;
    }
}
