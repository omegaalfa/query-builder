<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\Interfaces\CacheInterface;
use Omegaalfa\QueryBuilder\Internal\TransactionalCacheDependencyRegistry;
use Omegaalfa\QueryBuilder\QueryBuilder;
use PHPUnit\Framework\TestCase;
use WeakReference;

final class SQLiteTransactionalCacheDependencyContextTest extends TestCase
{
    private PDOConnection $connection;
    private TransactionalDependencyTestCache $cache;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }

        $this->connection = new PDOConnection(new DatabaseSettings('sqlite', '', ':memory:', 0, '', ''));
        $pdo = $this->connection->pdo();
        $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE other_items (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
        $this->cache = new TransactionalDependencyTestCache();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->disconnect();
        }
    }

    public function testDependenciesAreTrackedAtTheActiveManagedTransactionLevel(): void
    {
        $this->connection->beginTransaction();
        $this->write('items', 1, $this->cache);

        $active = TransactionalCacheDependencyRegistry::activeDependencies($this->connection, $this->cache);
        self::assertArrayHasKey(1, $active);
        self::assertNotEmpty($active[1]);
        self::assertSame([], TransactionalCacheDependencyRegistry::committedDependencies($this->connection, $this->cache));
        self::assertSame(0, $this->cache->flushCalls);

        $this->connection->rollBack();
        self::assertSame([], TransactionalCacheDependencyRegistry::activeDependencies($this->connection, $this->cache));
        self::assertSame([], TransactionalCacheDependencyRegistry::committedDependencies($this->connection, $this->cache));
    }

    public function testBuildersShareTheSameConnectionDependencyContext(): void
    {
        $this->connection->beginTransaction();
        $this->write('items', 1, $this->cache);
        $this->write('other_items', 2, $this->cache);

        $active = TransactionalCacheDependencyRegistry::activeDependencies($this->connection, $this->cache);
        self::assertCount(1, $active);
        self::assertCount(2, $active[1]);

        $this->connection->rollBack();
    }

    public function testDependencySetsAreSeparatedByCacheBackend(): void
    {
        $secondCache = new TransactionalDependencyTestCache();
        $this->connection->beginTransaction();
        $this->write('items', 1, $this->cache);
        $this->write('other_items', 2, $secondCache);

        self::assertNotEmpty(TransactionalCacheDependencyRegistry::activeDependencies($this->connection, $this->cache));
        self::assertNotEmpty(TransactionalCacheDependencyRegistry::activeDependencies($this->connection, $secondCache));
        self::assertNotSame(
            TransactionalCacheDependencyRegistry::activeDependencies($this->connection, $this->cache)[1],
            TransactionalCacheDependencyRegistry::activeDependencies($this->connection, $secondCache)[1],
        );

        $this->connection->rollBack();
    }

    public function testReleasedSavepointDependenciesMergeIntoParentLevel(): void
    {
        $this->connection->beginTransaction();
        $this->write('items', 1, $this->cache);
        $this->connection->beginTransaction();
        $this->write('other_items', 2, $this->cache);

        self::assertArrayHasKey(1, TransactionalCacheDependencyRegistry::activeDependencies($this->connection, $this->cache));
        self::assertArrayHasKey(2, TransactionalCacheDependencyRegistry::activeDependencies($this->connection, $this->cache));

        $this->connection->commit();

        $active = TransactionalCacheDependencyRegistry::activeDependencies($this->connection, $this->cache);
        self::assertSame([1], array_keys($active));
        self::assertCount(2, $active[1]);
        self::assertSame([], TransactionalCacheDependencyRegistry::committedDependencies($this->connection, $this->cache));

        $this->connection->rollBack();
    }

    public function testRollbackToSavepointDiscardsOnlyThatLevelDependencies(): void
    {
        $this->connection->beginTransaction();
        $this->write('items', 1, $this->cache);
        $this->connection->beginTransaction();
        $this->write('other_items', 2, $this->cache);
        $childDependencies = TransactionalCacheDependencyRegistry::activeDependencies($this->connection, $this->cache)[2];

        $this->connection->rollBack();

        $active = TransactionalCacheDependencyRegistry::activeDependencies($this->connection, $this->cache);
        self::assertSame([1], array_keys($active));
        self::assertNotContains($childDependencies[0], $active[1]);
        self::assertNotEmpty($active[1]);

        $this->connection->rollBack();
    }

    public function testOuterRollbackDiscardsAllPendingDependencies(): void
    {
        $this->connection->beginTransaction();
        $this->write('items', 1, $this->cache);
        $this->connection->beginTransaction();
        $this->write('other_items', 2, $this->cache);
        $this->connection->rollBack();
        $this->connection->rollBack();

        self::assertFalse($this->connection->pdo()->inTransaction());
        self::assertSame([], TransactionalCacheDependencyRegistry::activeDependencies($this->connection, $this->cache));
        self::assertSame([], TransactionalCacheDependencyRegistry::committedDependencies($this->connection, $this->cache));
    }

    public function testOutermostCommitConsolidatesDependenciesForFutureInvalidation(): void
    {
        $this->connection->beginTransaction();
        $this->write('items', 1, $this->cache);
        $this->connection->beginTransaction();
        $this->write('other_items', 2, $this->cache);
        $this->connection->commit();
        $this->connection->commit();

        self::assertFalse($this->connection->pdo()->inTransaction());
        self::assertSame([], TransactionalCacheDependencyRegistry::activeDependencies($this->connection, $this->cache));
        self::assertSame([], TransactionalCacheDependencyRegistry::committedDependencies($this->connection, $this->cache));
        self::assertSame(1, $this->cache->flushCalls);
    }

    public function testConnectionCanTrackNewDependenciesAfterRollback(): void
    {
        $this->connection->beginTransaction();
        $this->write('items', 1, $this->cache);
        $this->connection->rollBack();

        $this->connection->beginTransaction();
        $this->write('other_items', 2, $this->cache);

        $active = TransactionalCacheDependencyRegistry::activeDependencies($this->connection, $this->cache);
        self::assertSame([1], array_keys($active));
        self::assertCount(1, $active[1]);

        $this->connection->rollBack();
    }

    public function testConnectionCanBeReusedAfterCommit(): void
    {
        $this->connection->beginTransaction();
        $this->write('items', 1, $this->cache);
        $this->connection->commit();
        $committed = TransactionalCacheDependencyRegistry::committedDependencies($this->connection, $this->cache);

        $this->connection->beginTransaction();
        $this->write('other_items', 2, $this->cache);
        $this->connection->rollBack();

        self::assertSame([], TransactionalCacheDependencyRegistry::activeDependencies($this->connection, $this->cache));
        self::assertSame($committed, TransactionalCacheDependencyRegistry::committedDependencies($this->connection, $this->cache));
    }

    public function testDirectPdoTransactionIsNotTrackedAsManaged(): void
    {
        $this->connection->pdo()->beginTransaction();
        $this->write('items', 1, $this->cache);

        self::assertSame(0, $this->connection->getTransactionLevel());
        self::assertSame([], TransactionalCacheDependencyRegistry::activeDependencies($this->connection, $this->cache));
        self::assertSame([], TransactionalCacheDependencyRegistry::committedDependencies($this->connection, $this->cache));

        $this->connection->pdo()->rollBack();
    }

    public function testContextDoesNotKeepCacheBackendAlive(): void
    {
        $cache = new TransactionalDependencyTestCache();
        $cacheReference = WeakReference::create($cache);
        $this->connection->beginTransaction();
        $this->write('items', 1, $cache);

        unset($cache);
        gc_collect_cycles();

        self::assertNull($cacheReference->get());
        $this->connection->rollBack();
    }

    private function write(string $table, int $id, CacheInterface $cache): void
    {
        (new QueryBuilder($this->connection, cache: $cache))
            ->insert($table, ['id' => $id, 'value' => 'value-' . $id])
            ->execute();
    }
}

final class TransactionalDependencyTestCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $entries = [];

    /** @var array<string, list<string>> */
    private array $tagsByKey = [];

    public int $flushCalls = 0;

    public function has(string $key): bool
    {
        return isset($this->entries[$key]);
    }

    public function get(string $key): mixed
    {
        return $this->entries[$key] ?? null;
    }

    public function set(string $key, mixed $value, int $ttl): void
    {
        $this->entries[$key] = $value;
    }

    /** @param list<string> $tags */
    public function setWithTags(string $key, mixed $value, int $ttl, array $tags): void
    {
        $this->entries[$key] = $value;
        $this->tagsByKey[$key] = $tags;
    }

    /** @param list<string> $tags */
    public function flushTags(array $tags): bool
    {
        $this->flushCalls++;
        foreach ($this->tagsByKey as $key => $entryTags) {
            if (array_intersect($tags, $entryTags) !== []) {
                unset($this->entries[$key], $this->tagsByKey[$key]);
            }
        }
        return true;
    }

    public function delete(string $key): void
    {
        unset($this->entries[$key], $this->tagsByKey[$key]);
    }

    public function deletePattern(string $pattern): bool
    {
        return true;
    }

    public function clear(): bool
    {
        $this->entries = [];
        $this->tagsByKey = [];
        return true;
    }

    public function getMultiple(array $keys): array
    {
        return array_intersect_key($this->entries, array_flip($keys));
    }
}
