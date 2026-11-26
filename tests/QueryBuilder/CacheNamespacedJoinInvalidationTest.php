<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\QueryBuilder;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class CacheNamespacedJoinInvalidationTest extends TestCase
{
    private TagAwareDependencyCache $cache;

    /** @var list<PDOConnection> */
    private array $connections = [];

    /** @var list<string> */
    private array $databaseFiles = [];

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }

        $this->cache = new TagAwareDependencyCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            $connection->disconnect();
        }
        foreach ($this->databaseFiles as $file) {
            foreach ([$file, $file . '-journal', $file . '-wal', $file . '-shm'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function testSimpleSelectUsesDatabaseNamespacedTableTag(): void
    {
        $connection = $this->memoryConnection();
        $connection->pdo()->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, value TEXT)');
        $connection->pdo()->exec("INSERT INTO items VALUES (1, 'cached')");

        $this->read($connection)->select('items', ['value'])->cache(300)->execute();

        $identity = $this->connectionIdentity($connection);
        self::assertContains($this->tableTag($identity, 'items'), $this->cache->lastTags);
        self::assertContains('qb:' . $identity . ':database', $this->cache->lastTags);
        self::assertCount(2, $this->cache->lastTags);
    }

    public function testAliasedMultiTableJoinTracksRealTablesAndInvalidatesFromJoinedWrites(): void
    {
        $connection = $this->memoryConnection();
        $pdo = $connection->pdo();
        $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, category_id INTEGER)');
        $pdo->exec('CREATE TABLE details (item_id INTEGER, description TEXT)');
        $pdo->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY, label TEXT)');
        $pdo->exec("INSERT INTO items VALUES (1, 10)");
        $pdo->exec("INSERT INTO details VALUES (1, 'old')");
        $pdo->exec("INSERT INTO categories VALUES (10, 'category')");

        $read = fn () => $this->read($connection)
            ->select('items AS i', ['i.id', 'd.description', 'c.label'])
            ->join('details AS d', 'i.id', '=', 'd.item_id')
            ->join('categories AS c', 'i.category_id', '=', 'c.id')
            ->cache(300)
            ->execute();

        self::assertSame([['id' => 1, 'description' => 'old', 'label' => 'category']], $read()->data);
        $identity = $this->connectionIdentity($connection);
        self::assertContains($this->tableTag($identity, 'items'), $this->cache->lastTags);
        self::assertContains($this->tableTag($identity, 'details'), $this->cache->lastTags);
        self::assertContains($this->tableTag($identity, 'categories'), $this->cache->lastTags);
        self::assertCount(4, $this->cache->lastTags);

        $this->read($connection)->update('details', ['description' => 'updated'])->where('item_id', '=', 1)->execute();
        self::assertSame('updated', $read()->data[0]['description']);

        $this->read($connection)->insert('details', ['item_id' => 1, 'description' => 'added'])->execute();
        self::assertCount(2, $read()->data);

        $this->read($connection)->delete('details')->where('description', '=', 'updated')->execute();
        self::assertSame('added', $read()->data[0]['description']);

        self::assertContains($this->tableTag($identity, 'details'), $this->cache->flushedTags);
    }

    public function testIndependentMemoryDatabasesUseDifferentTagNamespaces(): void
    {
        $first = $this->memoryConnection();
        $second = $this->memoryConnection();
        foreach ([[$first, 'first'], [$second, 'second']] as [$connection, $value]) {
            $connection->pdo()->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, value TEXT)');
            $statement = $connection->pdo()->prepare('INSERT INTO items VALUES (1, ?)');
            $statement->execute([$value]);
        }

        $firstResult = $this->read($first)->select('items', ['value'])->cache(300)->execute();
        $firstTags = $this->cache->lastTags;
        $secondResult = $this->read($second)->select('items', ['value'])->cache(300)->execute();
        $secondTags = $this->cache->lastTags;

        self::assertSame([['value' => 'first']], $firstResult->data);
        self::assertSame([['value' => 'second']], $secondResult->data);
        self::assertNotSame($firstTags, $secondTags);
        self::assertSame(0, $this->cache->hits);

        $this->read($first)->update('items', ['value' => 'changed'])->execute();
        self::assertSame([['value' => 'second']], $this->read($second)->select('items', ['value'])->cache(300)->execute()->data);
        self::assertSame(1, $this->cache->hits);
    }

    public function testSeparateConnectionsToSameSqliteFileShareCacheIdentity(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'qb-tag-namespace-');
        self::assertNotFalse($file);
        $this->databaseFiles[] = $file;
        $first = $this->fileConnection($file);
        $first->pdo()->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, value TEXT)');
        $first->pdo()->exec("INSERT INTO items VALUES (1, 'shared')");
        $second = $this->fileConnection($file);

        $firstResult = $this->read($first)->select('items', ['value'])->cache(300)->execute();
        $secondResult = $this->read($second)->select('items', ['value'])->cache(300)->execute();

        self::assertSame([['value' => 'shared']], $firstResult->data);
        self::assertSame([['value' => 'shared']], $secondResult->data);
        self::assertSame(1, $this->cache->hits);
        self::assertSame($this->cache->tagsByKey[array_key_first($this->cache->tagsByKey)], $this->cache->lastTags);
    }

    public function testUnidentifiableStructuredTableExecutesWithoutUsingCache(): void
    {
        $connection = $this->memoryConnection();
        $connection->pdo()->exec('CREATE TABLE "items with space" (value TEXT)');
        $connection->pdo()->exec("INSERT INTO \"items with space\" VALUES ('ok')");

        $result = $this->read($connection)->select('items with space', ['value'])->cache(300)->execute();

        self::assertSame([['value' => 'ok']], is_array($result->data) ? $result->data : iterator_to_array($result->data, false));
        self::assertSame(0, $this->cache->lookups);
        self::assertSame(0, $this->cache->sets);
    }

    public function testBuilderReuseClearsJoinDependenciesAndMainSchemaMatchesUnqualifiedWrites(): void
    {
        $connection = $this->memoryConnection();
        $pdo = $connection->pdo();
        $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, value TEXT)');
        $pdo->exec('CREATE TABLE details (item_id INTEGER, description TEXT)');
        $pdo->exec("INSERT INTO items VALUES (1, 'item')");
        $pdo->exec("INSERT INTO details VALUES (1, 'detail')");

        $builder = $this->read($connection);
        $builder->select('items AS i', ['i.id', 'd.description'])
            ->join('details AS d', 'i.id', '=', 'd.item_id')
            ->cache(300)
            ->execute();

        $builder->select('details', ['description'])->cache(300)->execute();
        self::assertCount(2, $this->cache->lastTags);
        self::assertContains($this->tableTag($this->connectionIdentity($connection), 'details'), $this->cache->lastTags);
        self::assertNotContains($this->tableTag($this->connectionIdentity($connection), 'items'), $this->cache->lastTags);

        $qualified = fn () => $this->read($connection)->select('main.items', ['value'])->cache(300)->execute();
        self::assertSame([['value' => 'item']], $qualified()->data);
        $this->read($connection)->insert('items', ['id' => 2, 'value' => 'second'])->execute();
        self::assertCount(2, $qualified()->data);
    }

    public function testTagsDoNotExposeDatabaseCredentialsOrRawTableNames(): void
    {
        $connection = $this->memoryConnection();
        $connection->pdo()->exec('CREATE TABLE secret_table (value TEXT)');
        $builder = $this->read($connection)->select('secret_table', ['value'])->cache(300);
        $builder->execute();

        $serializedTags = implode('|', $this->cache->lastTags);
        self::assertStringNotContainsString('secret_table', $serializedTags);
        self::assertStringNotContainsString('credential-marker', $serializedTags);
        self::assertMatchesRegularExpression('/^qb:[a-f0-9]{32}:(?:table:[a-f0-9]{32}|database)$/', $this->cache->lastTags[0]);
    }

    private function read(PDOConnection $connection): QueryBuilder
    {
        return new QueryBuilder($connection, cache: $this->cache);
    }

    private function memoryConnection(): PDOConnection
    {
        return $this->connection(':memory:');
    }

    private function fileConnection(string $file): PDOConnection
    {
        return $this->connection($file);
    }

    private function connection(string $database): PDOConnection
    {
        $connection = new PDOConnection(new DatabaseSettings('sqlite', '', $database, 0, '', ''));
        $this->connections[] = $connection;
        return $connection;
    }

    private function connectionIdentity(PDOConnection $connection): string
    {
        $builder = $this->read($connection)->select('items')->cache(300);
        return (string) (new ReflectionClass($builder))->getMethod('cacheConnectionIdentity')->invoke($builder);
    }

    private function tableTag(string $identity, string $table): string
    {
        return 'qb:' . $identity . ':table:' . hash('xxh128', $table);
    }
}

final class TagAwareDependencyCache implements \Omegaalfa\QueryBuilder\Interfaces\CacheInterface
{
    /** @var array<string, mixed> */
    private array $entries = [];

    /** @var array<string, list<string>> */
    public array $tagsByKey = [];

    /** @var list<string> */
    public array $lastTags = [];

    /** @var list<string> */
    public array $flushedTags = [];

    public int $hits = 0;
    public int $lookups = 0;
    public int $sets = 0;

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
        array_push($this->flushedTags, ...$tags);
        foreach ($this->tagsByKey as $key => $entryTags) {
            if (array_intersect($tags, $entryTags) !== []) {
                unset($this->tagsByKey[$key], $this->entries[$key]);
            }
        }
        return true;
    }

    public function delete(string $key): void { unset($this->entries[$key], $this->tagsByKey[$key]); }
    public function deletePattern(string $pattern): bool { return true; }
    public function clear(): bool { $this->entries = []; $this->tagsByKey = []; return true; }
    public function getMultiple(array $keys): array { return array_intersect_key($this->entries, array_flip($keys)); }

    /** @param list<string> $tags */
    private function store(string $key, mixed $value, array $tags): void
    {
        $this->sets++;
        $this->entries[$key] = $value;
        $this->tagsByKey[$key] = $tags;
        $this->lastTags = $tags;
    }
}