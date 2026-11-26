<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\Exceptions\DatabaseException;
use Omegaalfa\QueryBuilder\Interfaces\CacheInterface;
use Omegaalfa\QueryBuilder\Internal\TransactionalCacheDependencyRegistry;
use Omegaalfa\QueryBuilder\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class SQLitePostCommitCacheInvalidationTest extends TestCase
{
    private PDOConnection $connection;
    private SQLitePostCommitTestCache $cache;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }

        $this->connection = new PDOConnection(new DatabaseSettings('sqlite', '', ':memory:', 0, '', ''));
        $pdo = $this->connection->pdo();
        $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE other_items (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE parents (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE deferred_items (id INTEGER PRIMARY KEY, parent_id INTEGER, FOREIGN KEY (parent_id) REFERENCES parents(id) DEFERRABLE INITIALLY DEFERRED)');
        $this->cache = new SQLitePostCommitTestCache();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->disconnect();
        }
    }

    public function testInsertUpdateAndDeleteInvalidateOnceAfterManagedOuterCommit(): void
    {
        $this->connection->beginTransaction();
        $this->builder()->insert('items', ['id' => 1, 'value' => 'first'])->execute();
        $this->builder()->update('items', ['value' => 'updated'])->where('id', '=', 1)->execute();
        $this->builder()->delete('items')->where('id', '=', 1)->execute();

        self::assertSame(0, $this->cache->flushCalls);
        $this->connection->commit();

        self::assertSame(1, $this->cache->flushCalls);
        self::assertCount(1, $this->cache->flushBatches);
        self::assertNotEmpty($this->cache->flushBatches[0]);
        self::assertSame([], TransactionalCacheDependencyRegistry::committedDependencies($this->connection, $this->cache));
    }

    public function testSavepointReleaseDoesNotFlushBeforeOuterCommit(): void
    {
        $this->connection->beginTransaction();
        $this->builder()->insert('items', ['id' => 1, 'value' => 'outer'])->execute();
        $this->connection->beginTransaction();
        $this->builder()->insert('other_items', ['id' => 2, 'value' => 'inner'])->execute();

        $this->connection->commit();
        self::assertSame(0, $this->cache->flushCalls);
        self::assertSame([], TransactionalCacheDependencyRegistry::committedDependencies($this->connection, $this->cache));

        $this->connection->commit();
        self::assertSame(1, $this->cache->flushCalls);
        self::assertCount(2, $this->cache->flushBatches[0]);
    }

    public function testRollbackToSavepointDiscardsOnlyItsTagsBeforeOuterCommit(): void
    {
        $this->connection->beginTransaction();
        $this->builder()->insert('items', ['id' => 1, 'value' => 'outer'])->execute();
        $outerTags = TransactionalCacheDependencyRegistry::activeDependencies($this->connection, $this->cache)[1];
        $this->connection->beginTransaction();
        $this->builder()->insert('other_items', ['id' => 2, 'value' => 'inner'])->execute();
        $this->connection->rollBack();

        self::assertSame(0, $this->cache->flushCalls);
        $this->connection->commit();

        self::assertSame(1, $this->cache->flushCalls);
        self::assertSame($outerTags, $this->cache->flushBatches[0]);
    }

    public function testOuterRollbackDoesNotInvalidateAnyPendingTags(): void
    {
        $this->connection->beginTransaction();
        $this->builder()->insert('items', ['id' => 1, 'value' => 'rolled back'])->execute();
        $this->connection->beginTransaction();
        $this->builder()->insert('other_items', ['id' => 2, 'value' => 'also rolled back'])->execute();

        $this->connection->rollBack();
        $this->connection->rollBack();

        self::assertSame(0, $this->cache->flushCalls);
        self::assertSame([], TransactionalCacheDependencyRegistry::activeDependencies($this->connection, $this->cache));
        self::assertSame([], TransactionalCacheDependencyRegistry::committedDependencies($this->connection, $this->cache));
    }

    public function testMultipleBuildersAndBackendsAreFlushedSeparatelyOnce(): void
    {
        $secondCache = new SQLitePostCommitTestCache();
        $this->connection->beginTransaction();
        $this->builder($this->cache)->insert('items', ['id' => 1, 'value' => 'first backend'])->execute();
        $this->builder($this->cache)->insert('other_items', ['id' => 2, 'value' => 'same backend'])->execute();
        $this->builder($secondCache)->insert('items', ['id' => 3, 'value' => 'second backend'])->execute();

        self::assertSame(0, $this->cache->flushCalls);
        self::assertSame(0, $secondCache->flushCalls);
        $this->connection->commit();

        self::assertSame(1, $this->cache->flushCalls);
        self::assertCount(2, $this->cache->flushBatches[0]);
        self::assertSame(1, $secondCache->flushCalls);
        self::assertCount(1, $secondCache->flushBatches[0]);
    }

    public function testIndependentConnectionsKeepTheirPendingDependenciesSeparate(): void
    {
        $secondConnection = new PDOConnection(new DatabaseSettings('sqlite', '', ':memory:', 0, '', ''));
        try {
            $secondConnection->pdo()->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
            $this->connection->beginTransaction();
            $secondConnection->beginTransaction();
            $this->builder()->insert('items', ['id' => 1, 'value' => 'first connection'])->execute();
            $this->builderFor($secondConnection)->insert('items', ['id' => 2, 'value' => 'second connection'])->execute();

            $secondDependencies = TransactionalCacheDependencyRegistry::activeDependencies($secondConnection, $this->cache);
            self::assertNotEmpty($secondDependencies);

            $this->connection->commit();

            self::assertSame(1, $this->cache->flushCalls);
            self::assertSame($secondDependencies, TransactionalCacheDependencyRegistry::activeDependencies($secondConnection, $this->cache));
            $secondConnection->rollBack();
            self::assertSame(1, $this->cache->flushCalls);
        } finally {
            $secondConnection->disconnect();
        }
    }

    public function testAutocommitWriteStillInvalidatesImmediately(): void
    {
        $this->builder()->insert('items', ['id' => 1, 'value' => 'autocommit'])->execute();

        self::assertSame(1, $this->cache->flushCalls);
        self::assertSame(1, count($this->cache->flushBatches[0]));
    }

    public function testDirectPdoTransactionKeepsProvisionalImmediateInvalidation(): void
    {
        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();
        $this->builder()->insert('items', ['id' => 1, 'value' => 'direct'])->execute();

        self::assertTrue($pdo->inTransaction());
        self::assertSame(0, $this->connection->getTransactionLevel());
        self::assertSame(1, $this->cache->flushCalls);
        self::assertSame([], TransactionalCacheDependencyRegistry::activeDependencies($this->connection, $this->cache));

        $pdo->rollBack();
    }

    public function testFailedManagedCommitDoesNotPublishPendingInvalidation(): void
    {
        $pdo = $this->connection->pdo();
        $this->connection->beginTransaction();
        $this->builder()->insert('deferred_items', ['id' => 1, 'parent_id' => 999])->execute();
        self::assertSame(0, $this->cache->flushCalls);

        try {
            $this->connection->commit();
            self::fail('The deferred foreign key must reject the commit.');
        } catch (DatabaseException) {
            self::assertTrue($pdo->inTransaction());
            self::assertSame(1, $this->connection->getTransactionLevel());
            self::assertSame(0, $this->cache->flushCalls);
        }

        $this->connection->rollBack();
        self::assertSame([], TransactionalCacheDependencyRegistry::activeDependencies($this->connection, $this->cache));
    }

    public function testReturningAndChunkedInsertBatchWaitForManagedCommit(): void
    {
        $this->connection->beginTransaction();
        $returned = $this->builder()
            ->insert('items', ['id' => 1, 'value' => 'returning'])
            ->returning(['id'])
            ->execute();
        self::assertSame([['id' => 1]], is_array($returned->data) ? $returned->data : iterator_to_array($returned->data, false));

        $rows = [];
        for ($id = 2; $id <= 902; $id++) {
            $rows[] = ['id' => $id, 'value' => 'batch-' . $id];
        }
        $batchResult = $this->builder()->insertBatch('items', $rows)->execute();
        self::assertSame(901, $batchResult->count);
        self::assertSame(0, $this->cache->flushCalls);

        $this->connection->commit();
        self::assertSame(1, $this->cache->flushCalls);
    }

    public function testFalseAndThrowingFlushResultsAreLoggedWithoutFailingCommittedTransaction(): void
    {
        $logPath = tempnam(sys_get_temp_dir(), 'qb-post-commit-log-');
        self::assertNotFalse($logPath);
        $originalLog = ini_get('error_log');
        ini_set('error_log', $logPath);

        try {
            $this->cache->flushMode = 'false';
            $this->connection->beginTransaction();
            $this->builder()->insert('items', ['id' => 1, 'value' => 'false result'])->execute();
            self::assertTrue($this->connection->commit());
            self::assertFalse($this->connection->pdo()->inTransaction());
            self::assertSame([], TransactionalCacheDependencyRegistry::committedDependencies($this->connection, $this->cache));

            $this->cache->flushMode = 'throw';
            $this->connection->beginTransaction();
            $this->builder()->insert('items', ['id' => 2, 'value' => 'exception'])->execute();
            self::assertTrue($this->connection->commit());
            self::assertFalse($this->connection->pdo()->inTransaction());
            self::assertSame([], TransactionalCacheDependencyRegistry::committedDependencies($this->connection, $this->cache));

            $log = file_get_contents($logPath);
            self::assertIsString($log);
            self::assertStringContainsString('flushTags() returned false', $log);
            self::assertStringContainsString('controlled flush exception', $log);
            self::assertSame(2, $this->cache->flushCalls);
        } finally {
            ini_set('error_log', $originalLog === false ? '' : $originalLog);
            if (is_file($logPath)) {
                unlink($logPath);
            }
        }
    }

    private function builder(?CacheInterface $cache = null): QueryBuilder
    {
        return new QueryBuilder($this->connection, cache: $cache ?? $this->cache);
    }

    private function builderFor(PDOConnection $connection): QueryBuilder
    {
        return new QueryBuilder($connection, cache: $this->cache);
    }
}

final class SQLitePostCommitTestCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $entries = [];

    /** @var array<string, list<string>> */
    private array $tagsByKey = [];

    /** @var list<list<string>> */
    public array $flushBatches = [];

    public int $flushCalls = 0;

    public string $flushMode = 'success';

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
        $this->flushBatches[] = $tags;
        if ($this->flushMode === 'throw') {
            throw new \RuntimeException('controlled flush exception');
        }
        if ($this->flushMode === 'false') {
            return false;
        }

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