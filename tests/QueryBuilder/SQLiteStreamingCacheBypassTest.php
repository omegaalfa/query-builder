<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\Interfaces\CacheInterface;
use Omegaalfa\QueryBuilder\QueryBuilder;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

final class SQLiteStreamingCacheBypassTest extends TestCase
{
    private PDOConnection $connection;
    private PDO $pdo;
    private StreamingAuditCache $cache;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }

        $this->connection = new PDOConnection(new DatabaseSettings('sqlite', '', ':memory:', 0, '', ''));
        $this->pdo = $this->connection->pdo();
        $this->pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
        $this->pdo->exec("INSERT INTO items VALUES (1, 'one'), (2, 'two'), (3, 'three')");
        $this->cache = new StreamingAuditCache();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->disconnect();
        }
    }

    public function testExecuteFalseWithoutCacheReturnsLazyGenerator(): void
    {
        $connection = $this->streamingConnection();
        try {
            $result = (new QueryBuilder($connection))
                ->select('items', ['id', 'value'])
                ->where('id', '>', 1)
                ->orderBy('id')
                ->execute(false);

            self::assertInstanceOf(\Generator::class, $result->data);
            self::assertSame([['id' => 2, 'value' => 'two'], ['id' => 3, 'value' => 'three']], $this->rows($result->data));
        } finally {
            $connection->disconnect();
        }
    }

    public function testExecuteFalseWithTtlBypassesEveryCacheOperationAndConsumesTtl(): void
    {
        $connection = $this->streamingConnection();
        try {
            $pdo = $connection->pdo(false);
            $cache = new StreamingAuditCache();
            $builder = new QueryBuilder($connection, cache: $cache);
            $builder->select('items', ['id', 'value'])->orderBy('id')->cache(90);
            $key = (new ReflectionMethod($builder, 'generateCacheKey'))->invoke($builder);
            $cache->seed($key, ['data' => [['id' => 99, 'value' => 'stale']], 'count' => 1, 'pagination' => null]);

            $result = $builder->execute(false);

            self::assertInstanceOf(\Generator::class, $result->data);
            self::assertSame(['id' => 1, 'value' => 'one'], $result->data->current());
            self::assertSame(0, $cache->hasCalls);
            self::assertSame(0, $cache->getCalls);
            self::assertSame(0, $cache->setCalls);
            self::assertSame(0, $cache->taggedSetCalls);
            self::assertSame(0, $this->cacheTtl($builder));

            $result = $builder->select('items', ['id', 'value'])->where('id', '=', 3)->execute(false);
            self::assertInstanceOf(\Generator::class, $result->data);
            self::assertSame([['id' => 3, 'value' => 'three']], $this->rows($result->data));
            self::assertSame(0, $cache->hasCalls);
            self::assertSame(0, $cache->setCalls);
            self::assertSame(0, $this->cacheTtl($builder));
            self::assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM items')->fetchColumn());
        } finally {
            $connection->disconnect();
        }
    }

    public function testLargeExecuteFalseResultIsNotMaterializedBeforeConsumption(): void
    {
        $connection = $this->streamingConnection();
        try {
            $pdo = $connection->pdo(false);
            $pdo->exec('CREATE TABLE large_items (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
            $insert = $pdo->prepare('INSERT INTO large_items VALUES (?, ?)');
            $payload = str_repeat('x', 512);
            for ($id = 1; $id <= 5000; $id++) {
                $insert->execute([$id, $payload]);
            }

            $cache = new StreamingAuditCache();
            $builder = new QueryBuilder($connection, cache: $cache);
            $memoryBefore = memory_get_usage(false);
            $result = $builder->select('large_items', ['id', 'value'])->orderBy('id')->cache(120)->execute(false);
            $memoryAtReturn = memory_get_usage(false);

            self::assertInstanceOf(\Generator::class, $result->data);
            self::assertLessThan(1024 * 1024, $memoryAtReturn - $memoryBefore);
            self::assertSame(1, $result->data->current()['id']);
            self::assertSame(0, $cache->hasCalls);
            self::assertSame(0, $cache->setCalls);
            self::assertSame(0, $cache->taggedSetCalls);
        } finally {
            $connection->disconnect();
        }
    }

    public function testExecuteTrueStillCachesMissAndHitAsMaterializedArrays(): void
    {
        $builder = new QueryBuilder($this->connection, cache: $this->cache);

        $miss = $builder->select('items', ['id', 'value'])->orderBy('id')->cache(60)->execute(true);
        self::assertIsArray($miss->data);
        self::assertSame(3, count($miss->data));

        $hit = $builder->select('items', ['id', 'value'])->orderBy('id')->cache(60)->execute();
        self::assertIsArray($hit->data);
        self::assertSame($miss->data, $hit->data);
        self::assertSame(2, $this->cache->hasCalls);
        self::assertSame(1, $this->cache->getCalls);
        self::assertSame(0, $this->cache->setCalls);
        self::assertSame(1, $this->cache->taggedSetCalls);
    }

    public function testExecuteFalseInsideTransactionStillBypassesCacheAndReturnsGenerator(): void
    {
        $connection = $this->streamingConnection();
        try {
            $pdo = $connection->pdo(false);
            $cache = new StreamingAuditCache();
            $builder = new QueryBuilder($connection, cache: $cache);
            $pdo->beginTransaction();

            $result = $builder->select('items', ['value'])->cache(120)->execute(false);

            self::assertInstanceOf(\Generator::class, $result->data);
            self::assertSame([['value' => 'one'], ['value' => 'two'], ['value' => 'three']], $this->rows($result->data));
            self::assertSame(0, $cache->hasCalls);
            self::assertSame(0, $cache->getCalls);
            self::assertSame(0, $cache->setCalls);
            self::assertSame(0, $cache->taggedSetCalls);
            self::assertSame(0, $this->cacheTtl($builder));

            $pdo->rollBack();
        } finally {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $connection->disconnect();
        }
    }

    public function testDmlStillInvalidatesCachedSelectResults(): void
    {
        $builder = new QueryBuilder($this->connection, cache: $this->cache);
        $builder->select('items', ['value'])->where('id', '=', 1)->cache(60)->execute();

        $builder->update('items', ['value' => 'updated'])->where('id', '=', 1)->execute();

        self::assertSame(1, $this->cache->flushCalls);
        self::assertSame('updated', $this->pdo->query('SELECT value FROM items WHERE id = 1')->fetchColumn());
    }

    private function streamingConnection(): PDOConnection
    {
        $connection = new PDOConnection(new DatabaseSettings('sqlite', '', ':memory:', 0, '', ''));
        $pdo = $connection->pdo(false);
        $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
        $pdo->exec("INSERT INTO items VALUES (1, 'one'), (2, 'two'), (3, 'three')");

        return $connection;
    }

    private function cacheTtl(QueryBuilder $builder): int
    {
        return (int) (new ReflectionProperty($builder, 'cacheTtl'))->getValue($builder);
    }

    /** @param iterable<mixed> $data
     * @return list<mixed>
     */
    private function rows(iterable $data): array
    {
        return is_array($data) ? $data : iterator_to_array($data, false);
    }
}

final class StreamingAuditCache implements CacheInterface
{
    /** @var array<string, array{payload: mixed, tags: list<string>}> */
    private array $entries = [];
    public int $hasCalls = 0;
    public int $getCalls = 0;
    public int $setCalls = 0;
    public int $taggedSetCalls = 0;
    public int $flushCalls = 0;

    public function has(string $key): bool
    {
        $this->hasCalls++;
        return isset($this->entries[$key]);
    }

    public function get(string $key): mixed
    {
        $this->getCalls++;
        return $this->entries[$key]['payload'] ?? null;
    }

    public function set(string $key, mixed $value, int $ttl): void
    {
        $this->setCalls++;
        $this->entries[$key] = ['payload' => $value, 'tags' => []];
    }

    public function setWithTags(string $key, mixed $value, int $ttl, array $tags): void
    {
        $this->taggedSetCalls++;
        $this->entries[$key] = ['payload' => $value, 'tags' => $tags];
    }

    public function flushTags(array $tags): bool
    {
        $this->flushCalls++;
        foreach ($this->entries as $key => $entry) {
            if (array_intersect($tags, $entry['tags']) !== []) {
                unset($this->entries[$key]);
            }
        }

        return true;
    }

    public function delete(string $key): void { unset($this->entries[$key]); }
    public function deletePattern(string $pattern): bool { return true; }
    public function clear(): bool { $this->entries = []; return true; }
    public function getMultiple(array $keys): array { return array_intersect_key($this->entries, array_flip($keys)); }

    public function seed(string $key, mixed $payload): void
    {
        $this->entries[$key] = ['payload' => $payload, 'tags' => []];
    }
}