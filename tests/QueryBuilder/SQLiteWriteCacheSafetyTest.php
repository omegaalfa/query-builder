<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\Interfaces\CacheInterface;
use Omegaalfa\QueryBuilder\Interfaces\ConnectionInterface;
use Omegaalfa\QueryBuilder\Interfaces\QueryLoggerInterface;
use Omegaalfa\QueryBuilder\QueryBuilder;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Throwable;

final class SQLiteWriteCacheSafetyTest extends TestCase
{
    private PDO $pdo;

    private SQLiteWriteCache $cache;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }

        $this->pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, item_key TEXT, value TEXT)');
        $this->pdo->exec('CREATE TABLE audit (event TEXT NOT NULL)');
        $this->pdo->exec(<<<'SQL'
CREATE TRIGGER items_insert_audit AFTER INSERT ON items
BEGIN
    INSERT INTO audit (event) VALUES ('insert');
END
SQL);
        $this->cache = new SQLiteWriteCache();
    }

    public function testRepeatedInsertExecutesAndTriggersDespiteMatchingCacheEntry(): void
    {
        $builder = $this->builder()->insert('items', ['item_key' => 'same', 'value' => 'v'])->cache(300);
        $key = (new ReflectionClass($builder))->getMethod('generateCacheKey')->invoke($builder);
        $this->cache->set($key, ['data' => [], 'count' => 0, 'pagination' => null], 300);

        $first = $builder->execute();
        $second = $this->builder()->insert('items', ['item_key' => 'same', 'value' => 'v'])->cache(300)->execute();

        self::assertSame(1, $first->count);
        self::assertSame(1, $second->count);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM items')->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM audit')->fetchColumn());
        self::assertSame(0, $this->cache->lookups);
        self::assertArrayHasKey($key, $this->cache->items);
    }

    public function testRepeatedUpdateExecutesAfterExternalChange(): void
    {
        $this->pdo->exec("INSERT INTO items (item_key, value) VALUES ('update', 'initial')");
        $run = fn () => $this->builder()
            ->update('items', ['value' => 'target'])
            ->where('item_key', '=', 'update')
            ->cache(300)
            ->execute();

        self::assertSame(1, $run()->count);
        $this->pdo->exec("UPDATE items SET value = 'external' WHERE item_key = 'update'");
        $result = $run();

        self::assertSame(1, $result->count);
        self::assertSame('target', $this->valueFor('update'));
        self::assertSame(0, $this->cache->lookups);
    }

    public function testRepeatedDeleteExecutesAfterExternalReinsert(): void
    {
        $this->pdo->exec("INSERT INTO items (item_key, value) VALUES ('delete', 'initial')");
        $run = fn () => $this->builder()
            ->delete('items')
            ->where('item_key', '=', 'delete')
            ->cache(300)
            ->execute();

        self::assertSame(1, $run()->count);
        $this->pdo->exec("INSERT INTO items (item_key, value) VALUES ('delete', 'external')");
        $result = $run();

        self::assertSame(1, $result->count);
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM items WHERE item_key = 'delete'")->fetchColumn());
        self::assertSame(0, $this->cache->lookups);
    }

    public function testRepeatedInsertBatchExecutesEveryBatch(): void
    {
        $rows = [['item_key' => 'batch-1', 'value' => '1'], ['item_key' => 'batch-2', 'value' => '2']];
        $run = fn () => $this->builder()->insertBatch('items', $rows)->cache(300)->execute();

        self::assertSame(2, $run()->count);
        self::assertSame(2, $run()->count);

        self::assertSame(4, (int) $this->pdo->query('SELECT COUNT(*) FROM items')->fetchColumn());
        self::assertSame(4, (int) $this->pdo->query('SELECT COUNT(*) FROM audit')->fetchColumn());
        self::assertSame(0, $this->cache->lookups);
    }

    public function testRepeatedUpsertExecutesAfterExternalChange(): void
    {
        $this->pdo->exec('CREATE UNIQUE INDEX items_item_key_unique ON items (item_key)');
        $this->pdo->exec("INSERT INTO items (item_key, value) VALUES ('upsert', 'initial')");
        $run = fn () => $this->builder()
            ->insert('items', ['item_key' => 'upsert', 'value' => 'target'])
            ->onConflict(['item_key'])
            ->doUpdate(['value'])
            ->cache(300)
            ->execute();

        self::assertSame(1, $run()->count);
        $this->pdo->exec("UPDATE items SET value = 'external' WHERE item_key = 'upsert'");
        $result = $run();

        self::assertSame(1, $result->count);
        self::assertSame('target', $this->valueFor('upsert'));
        self::assertSame(0, $this->cache->lookups);
    }

    public function testRepeatedReturningInsertReturnsFreshRowsAndIdentifiers(): void
    {
        $logger = new SQLiteWriteLogger();
        $returning = fn () => $this->builder($logger)
            ->insert('items', ['item_key' => 'returning', 'value' => 'v'])
            ->returning(['id'])
            ->cache(300)
            ->execute();
        $first = $returning();
        $second = $returning();

        self::assertSame([['id' => 1]], iterator_to_array($first->data, false));
        self::assertSame([['id' => 2]], iterator_to_array($second->data, false));
        self::assertSame(0, $first->count);
        self::assertSame(0, $second->count);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM items')->fetchColumn());
        self::assertSame(0, $this->cache->lookups);
        self::assertSame(2, $logger->queryCount());
    }

    public function testRepeatedReturningUpdateExecutesAfterExternalChange(): void
    {
        $this->pdo->exec("INSERT INTO items (item_key, value) VALUES ('returning-update', 'initial')");
        $run = fn () => $this->builder()
            ->update('items', ['value' => 'target'])
            ->where('item_key', '=', 'returning-update')
            ->returning(['id', 'value'])
            ->cache(300)
            ->execute();

        self::assertSame([['id' => 1, 'value' => 'target']], iterator_to_array($run()->data, false));
        $this->pdo->exec("UPDATE items SET value = 'external' WHERE item_key = 'returning-update'");
        $result = $run();

        self::assertSame([['id' => 1, 'value' => 'target']], iterator_to_array($result->data, false));
        self::assertSame('target', $this->valueFor('returning-update'));
        self::assertSame(0, $this->cache->lookups);
    }

    public function testRepeatedReturningDeleteExecutesAfterExternalReinsert(): void
    {
        $this->pdo->exec("INSERT INTO items (item_key, value) VALUES ('returning-delete', 'initial')");
        $run = fn () => $this->builder()
            ->delete('items')
            ->where('item_key', '=', 'returning-delete')
            ->returning(['id'])
            ->cache(300)
            ->execute();

        self::assertSame([['id' => 1]], iterator_to_array($run()->data, false));
        $this->pdo->exec("INSERT INTO items (item_key, value) VALUES ('returning-delete', 'external')");
        $result = $run();

        self::assertSame([['id' => 2]], iterator_to_array($result->data, false));
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM items WHERE item_key = 'returning-delete'")->fetchColumn());
        self::assertSame(0, $this->cache->lookups);
    }

    public function testInsertIdCountAndLoggingArePreservedWhenCacheIsEnabled(): void
    {
        $logger = new SQLiteWriteLogger();
        $run = fn () => $this->builder($logger)
            ->insert('items', ['item_key' => 'identity', 'value' => 'v'])
            ->returnLastInsertId()
            ->cache(300)
            ->execute();

        $first = $run();
        $second = $run();

        self::assertSame('1', $first->insertId);
        self::assertSame('2', $second->insertId);
        self::assertSame(1, $first->count);
        self::assertSame(1, $second->count);
        self::assertSame(2, $logger->queryCount());
        self::assertSame(0, $this->cache->lookups);
    }

    public function testStructuredSelectStillUsesCache(): void
    {
        $this->pdo->exec("INSERT INTO items (item_key, value) VALUES ('read', 'v')");
        $builder = $this->builder();
        $run = fn () => $builder->select('items', ['item_key', 'value'])
            ->where('item_key', '=', 'read')
            ->cache(300)
            ->execute();

        $first = $run();
        $second = $run();

        self::assertSame([['item_key' => 'read', 'value' => 'v']], iterator_to_array($first->data, false));
        self::assertSame([['item_key' => 'read', 'value' => 'v']], $second->data);
        self::assertSame(2, $this->cache->lookups);
        self::assertSame(1, $this->cache->hits);
        self::assertSame(1, $this->cache->sets);
    }

    public function testRawSelectAndUnknownCommandDoNotUseCache(): void
    {
        $builder = $this->builder();
        $rawFirst = $builder->raw('SELECT value FROM items')->cache(300)->execute();
        $rawSecond = $builder->raw('SELECT value FROM items')->execute();
        $pragmaFirst = $builder->raw('PRAGMA table_info(items)')->execute();
        $pragmaSecond = $builder->raw('PRAGMA table_info(items)')->execute();

        self::assertSame([], iterator_to_array($rawFirst->data, false));
        self::assertSame([], iterator_to_array($rawSecond->data, false));
        self::assertCount(3, iterator_to_array($pragmaFirst->data, false));
        self::assertCount(3, iterator_to_array($pragmaSecond->data, false));
        self::assertSame(0, $this->cache->lookups);
        self::assertSame(0, $this->cache->sets);
    }

    public function testCacheTtlDoesNotMakeLaterWritesCacheEligible(): void
    {
        $builder = $this->builder();
        $builder->select('items')->cache(300)->execute();
        $builder->select('items')->cache(300)->execute();
        self::assertSame(1, $this->cache->hits);
        self::assertSame(2, $this->cache->lookups);
        self::assertSame(1, $this->cache->sets);

        // A successful cached operation consumes its TTL; this SELECT runs uncached.
        $builder->select('items')->execute();
        self::assertSame(2, $this->cache->lookups);
        self::assertSame(1, $this->cache->sets);

        $firstInsert = $builder->insert('items', ['item_key' => 'after-read', 'value' => 'v'])->execute();
        $secondInsert = $builder->insert('items', ['item_key' => 'after-read', 'value' => 'v'])->execute();
        self::assertSame(1, $firstInsert->count);
        self::assertSame(1, $secondInsert->count);

        self::assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM items WHERE item_key = 'after-read'")->fetchColumn());
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM audit')->fetchColumn());

        $update = $builder->update('items', ['value' => 'updated'])->where('item_key', '=', 'after-read')->execute();
        $delete = $builder->delete('items')->where('item_key', '=', 'after-read')->execute();
        self::assertSame(2, $update->count);
        self::assertSame(2, $delete->count);
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM items WHERE item_key = 'after-read'")->fetchColumn());

        // All DML ran against the database without cache reads or result writes.
        self::assertSame(2, $this->cache->lookups);
        self::assertSame(1, $this->cache->hits);
        self::assertSame(1, $this->cache->sets);
        self::assertSame(4, $this->cache->flushes);

        // The write invalidated the cached SELECT; an explicitly cached read refreshes it.
        $afterWrites = $builder->select('items')->cache(300)->execute();
        self::assertSame([], $afterWrites->data);
        self::assertSame(3, $this->cache->lookups);
        self::assertSame(2, $this->cache->sets);
    }

    private function builder(?SQLiteWriteLogger $logger = null): QueryBuilder
    {
        $connection = new class($this->pdo) implements ConnectionInterface {
            public function __construct(private PDO $pdo) {}
            public function connect(): void {}
            public function pdo(bool $bufferedQuery = true): PDO { return $this->pdo; }
            public function disconnect(): void {}
            public function transaction(callable $callback): mixed { return $callback($this->pdo); }
            public function getDriver(): string { return 'sqlite'; }
        };

        return new QueryBuilder($connection, null, $this->cache, $logger);
    }

    private function valueFor(string $key): string|false
    {
        $statement = $this->pdo->prepare('SELECT value FROM items WHERE item_key = :key');
        $statement->execute(['key' => $key]);
        return $statement->fetchColumn();
    }
}

final class SQLiteWriteCache implements CacheInterface
{
    /** @var array<string, mixed> */
    public array $items = [];
    public int $lookups = 0;
    public int $hits = 0;
    public int $sets = 0;
    public int $flushes = 0;
    /** @var array<string, list<string>> */
    public array $tags = [];

    public function has(string $key): bool
    {
        $this->lookups++;
        return array_key_exists($key, $this->items);
    }

    public function get(string $key): mixed
    {
        if (array_key_exists($key, $this->items)) {
            $this->hits++;
        }
        return $this->items[$key] ?? null;
    }

    public function set(string $key, mixed $value, int $ttl): void
    {
        $this->sets++;
        $this->items[$key] = $value;
        $this->tags[$key] = [];
    }

    /** @param list<string> $tags */
    public function setWithTags(string $key, mixed $value, int $ttl, array $tags): void
    {
        $this->sets++;
        $this->items[$key] = $value;
        $this->tags[$key] = $tags;
    }

    /** @param list<string> $tags */
    public function flushTags(array $tags): bool
    {
        $this->flushes++;
        foreach ($this->tags as $key => $entryTags) {
            if (array_intersect($tags, $entryTags) !== []) {
                unset($this->items[$key], $this->tags[$key]);
            }
        }
        return true;
    }

    public function delete(string $key): void { unset($this->items[$key], $this->tags[$key]); }
    public function deletePattern(string $pattern): bool { return true; }
    public function clear(): bool { $this->items = []; return true; }
    public function getMultiple(array $keys): array { return array_intersect_key($this->items, array_flip($keys)); }
}

final class SQLiteWriteLogger implements QueryLoggerInterface
{
    /** @var list<array{sql: string, count: int}> */
    private array $queries = [];

    public function logQuery(string $sql, array $params, float $duration, int $rowCount): void
    {
        $this->queries[] = ['sql' => $sql, 'count' => $rowCount];
    }

    public function logError(string $sql, array $params, Throwable $error): void {}

    public function queryCount(): int { return count($this->queries); }
}
