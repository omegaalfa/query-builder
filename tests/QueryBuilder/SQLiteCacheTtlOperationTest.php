<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\Interfaces\CacheInterface;
use Omegaalfa\QueryBuilder\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class SQLiteCacheTtlOperationTest extends TestCase
{
    private PDOConnection $connection;
    private \PDO $pdo;
    private RecordingTtlCache $cache;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }

        $this->connection = new PDOConnection(new DatabaseSettings('sqlite', '', ':memory:', 0, '', ''));
        $this->pdo = $this->connection->pdo();
        $this->pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, value TEXT)');
        $this->pdo->exec("INSERT INTO items VALUES (1, 'one'), (2, 'two')");
        $this->cache = new RecordingTtlCache();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->disconnect();
        }
    }

    public function testCacheTtlAppliesToOneExecutionAndZeroOverridesPreviousTtl(): void
    {
        $builder = $this->builder();
        $builder->select('items', ['value'])->where('id', '=', 1)->cache(60)->execute();
        self::assertSame([60], $this->cache->ttls);

        $this->pdo->exec("UPDATE items SET value = 'fresh' WHERE id = 1");
        $builder->select('items', ['value'])->where('id', '=', 1)->cache(60)->cache(0)->execute();
        self::assertSame('fresh', $this->pdo->query('SELECT value FROM items WHERE id = 1')->fetchColumn());
        self::assertSame(0, $this->cache->hits);
        self::assertSame([60], $this->cache->ttls);

        $this->pdo->exec("UPDATE items SET value = 'newer' WHERE id = 1");
        $result = $builder->select('items', ['value'])->where('id', '=', 1)->execute();
        self::assertSame([['value' => 'newer']], $this->rows($result->data));
        self::assertSame([60], $this->cache->ttls);
    }

    public function testDefaultTtlAndBothFluentCallOrdersAreSupported(): void
    {
        $beforeSelect = $this->builder()->cache()->select('items', ['value'])->where('id', '=', 1)->execute();
        self::assertSame([['value' => 'one']], $this->rows($beforeSelect->data));
        self::assertSame([3600], $this->cache->ttls);

        $afterSelect = $this->builder()->select('items', ['value'])->where('id', '=', 2)->cache(17)->execute();
        self::assertSame([['value' => 'two']], $this->rows($afterSelect->data));
        self::assertSame([3600, 17], $this->cache->ttls);
    }

    public function testCacheHitAndMissBothConsumeTheOperationTtl(): void
    {
        $builder = $this->builder();
        $builder->select('items', ['value'])->where('id', '=', 1)->cache(30)->execute();
        $builder->select('items', ['value'])->where('id', '=', 1)->cache(30)->execute();
        self::assertSame(1, $this->cache->hits);

        $builder->select('items', ['value'])->where('id', '=', 2)->execute();
        self::assertSame(2, $this->cache->lookups);
        self::assertSame([30], $this->cache->ttls);
    }

    public function testNegativeTtlThrowsAndDoesNotReplaceThePreviousValue(): void
    {
        $builder = $this->builder()->select('items', ['value'])->cache(41);
        try {
            $builder->cache(-1);
            self::fail('A negative TTL must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Cache TTL cannot be negative.', $exception->getMessage());
        }

        $builder->execute();
        self::assertSame([41], $this->cache->ttls);
    }

    public function testSqlFailurePreservesTtlForRetry(): void
    {
        $builder = $this->builder()->select('missing_table')->cache(25);
        try {
            $builder->execute();
            self::fail('The invalid table query should fail.');
        } catch (\Throwable) {
            self::assertSame([], $this->cache->ttls);
        }

        $result = $builder->select('items', ['value'])->where('id', '=', 2)->execute();
        self::assertSame([['value' => 'two']], $this->rows($result->data));
        self::assertSame([25], $this->cache->ttls);
    }

    public function testWritesAndTransactionalBypassConsumeTtlWithoutLeakingIt(): void
    {
        $builder = $this->builder();
        $builder->select('items', ['value'])->where('id', '=', 1)->cache(20)->execute();
        $builder->update('items', ['value' => 'updated'])->where('id', '=', 1)->execute();
        self::assertSame([20], $this->cache->ttls);
        self::assertGreaterThanOrEqual(1, $this->cache->invalidations);
        $builder->insert('items', ['id' => 3, 'value' => 'inserted'])->execute();
        $builder->delete('items')->where('id', '=', 3)->execute();
        self::assertSame([20], $this->cache->ttls);

        $this->connection->beginTransaction();
        try {
            $builder->select('items', ['value'])->where('id', '=', 1)->cache(22)->execute();
        } finally {
            $this->connection->rollBack();
        }

        $builder->select('items', ['value'])->where('id', '=', 1)->execute();
        self::assertSame([20], $this->cache->ttls);
        self::assertSame('updated', $this->pdo->query('SELECT value FROM items WHERE id = 1')->fetchColumn());
    }

    public function testCacheBackendFailureDoesNotLeakTtlAndStreamingContractIsUnchanged(): void
    {
        $this->cache->failRead = true;
        $this->cache->failWrite = true;
        $builder = $this->builder();
        $result = $builder->select('items', ['value'])->cache(12)->execute();
        self::assertSame([['value' => 'one'], ['value' => 'two']], $this->rows($result->data));

        $this->cache->failRead = false;
        $this->cache->failWrite = false;
        $builder->select('items', ['value'])->execute();
        self::assertSame([], $this->cache->ttls);

        $streamConnection = new PDOConnection(new DatabaseSettings('sqlite', '', ':memory:', 0, '', ''));
        $streamConnection->pdo(false)->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, value TEXT)');
        $streamConnection->pdo(false)->exec("INSERT INTO items VALUES (1, 'one'), (2, 'two')");
        $stream = (new QueryBuilder($streamConnection))->select('items', ['value'])->execute(false);
        self::assertIsIterable($stream->data);
        self::assertSame([['value' => 'one'], ['value' => 'two']], $this->rows($stream->data));
        $streamConnection->disconnect();
    }

    private function builder(): QueryBuilder
    {
        return new QueryBuilder($this->connection, cache: $this->cache);
    }

    private function rows(iterable $rows): array
    {
        return is_array($rows) ? $rows : iterator_to_array($rows, false);
    }
}

final class RecordingTtlCache implements CacheInterface
{
    /** @var array<string, array{payload: mixed, tags: list<string>}> */
    private array $entries = [];
    /** @var list<int> */
    public array $ttls = [];
    public int $hits = 0;
    public int $lookups = 0;
    public int $invalidations = 0;
    public bool $failRead = false;
    public bool $failWrite = false;

    public function has(string $key): bool
    {
        $this->lookups++;
        if ($this->failRead) { throw new \RuntimeException('read failed'); }
        return isset($this->entries[$key]);
    }

    public function get(string $key): mixed
    {
        if ($this->failRead) { throw new \RuntimeException('read failed'); }
        if (isset($this->entries[$key])) { $this->hits++; return $this->entries[$key]['payload']; }
        return null;
    }

    public function set(string $key, mixed $value, int $ttl): void { $this->save($key, $value, $ttl, []); }
    public function setWithTags(string $key, mixed $value, int $ttl, array $tags): void { $this->save($key, $value, $ttl, $tags); }

    public function flushTags(array $tags): bool
    {
        $this->invalidations++;
        foreach ($this->entries as $key => $entry) {
            if (array_intersect($tags, $entry['tags']) !== []) { unset($this->entries[$key]); }
        }
        return true;
    }

    public function delete(string $key): void { unset($this->entries[$key]); }
    public function deletePattern(string $pattern): bool { return true; }
    public function clear(): bool { $this->entries = []; return true; }
    public function getMultiple(array $keys): array { return []; }

    private function save(string $key, mixed $value, int $ttl, array $tags): void
    {
        if ($this->failWrite) { throw new \RuntimeException('write failed'); }
        $this->ttls[] = $ttl;
        $this->entries[$key] = ['payload' => $value, 'tags' => $tags];
    }
}