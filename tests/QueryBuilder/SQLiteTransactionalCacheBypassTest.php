<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\Interfaces\CacheInterface;
use Omegaalfa\QueryBuilder\QueryBuilder;
use PDO;
use PHPUnit\Framework\TestCase;

final class SQLiteTransactionalCacheBypassTest extends TestCase
{
    private PDOConnection $connection;
    private PDO $pdo;
    private TransactionAwareTestCache $cache;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }

        $this->connection = new PDOConnection(new DatabaseSettings('sqlite', '', ':memory:', 0, '', ''));
        $this->pdo = $this->connection->pdo();
        $this->pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
        $this->pdo->exec("INSERT INTO items VALUES (1, 'confirmed')");
        $this->cache = new TransactionAwareTestCache();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->disconnect();
        }
    }

    public function testSelectOutsideTransactionStillUsesCache(): void
    {
        self::assertSame([['value' => 'confirmed']], $this->read()->data);
        self::assertSame([['value' => 'confirmed']], $this->read()->data);

        self::assertSame(2, $this->cache->lookups);
        self::assertSame(1, $this->cache->hits);
        self::assertSame(1, $this->cache->sets);
    }

    public function testManagedTransactionBypassesAnExistingEntryAndDoesNotCacheUncommittedData(): void
    {
        $this->read();
        $this->connection->beginTransaction();
        $this->pdo->exec("UPDATE items SET value = 'uncommitted' WHERE id = 1");

        self::assertSame([['value' => 'uncommitted']], $this->rows($this->read()->data));
        self::assertSame(1, $this->cache->lookups);
        self::assertSame(1, $this->cache->sets);

        $this->connection->rollBack();
        self::assertSame([['value' => 'confirmed']], $this->rows($this->read()->data));
        self::assertSame(2, $this->cache->lookups);
        self::assertSame(1, $this->cache->hits);
    }

    public function testDirectPdoTransactionStartedAfterBuilderConstructionBypassesCache(): void
    {
        $builder = $this->builder()->select('items', ['value'])->where('id', '=', 1)->cache(300);
        $this->pdo->beginTransaction();
        $this->pdo->exec("UPDATE items SET value = 'direct transaction' WHERE id = 1");

        self::assertSame([['value' => 'direct transaction']], $this->rows($builder->execute()->data));
        self::assertSame(0, $this->cache->lookups);
        self::assertSame(0, $this->cache->sets);

        $this->pdo->rollBack();
        self::assertSame([['value' => 'confirmed']], $this->rows($this->read()->data));
        self::assertSame(1, $this->cache->sets);
    }

    public function testDirectPdoTransactionIsDetectedEvenWhenStartedAfterBuilderConstruction(): void
    {
        $builder = $this->builder()->select('items', ['value'])->where('id', '=', 1)->cache(300);
        $builder->getQuerySql();
        $this->pdo->beginTransaction();

        self::assertSame([['value' => 'confirmed']], $this->rows($builder->execute()->data));
        self::assertSame(0, $this->cache->lookups);
        self::assertSame(0, $this->cache->sets);
        $this->pdo->rollBack();
    }

    public function testNestedSavepointReadsBypassCacheAndConnectionCanBeReusedAfterCommit(): void
    {
        $this->connection->beginTransaction();
        $this->connection->beginTransaction();
        $this->pdo->exec("UPDATE items SET value = 'nested' WHERE id = 1");

        self::assertSame([['value' => 'nested']], $this->rows($this->read()->data));
        self::assertSame(0, $this->cache->lookups);
        self::assertSame(0, $this->cache->sets);

        $this->connection->commit();
        $this->connection->commit();
        self::assertFalse($this->pdo->inTransaction());
        self::assertSame([['value' => 'nested']], $this->rows($this->read()->data));
        self::assertSame(1, $this->cache->sets);
    }

    public function testConnectionCanBeReusedAfterRollbackWithoutCachingRolledBackRows(): void
    {
        $this->connection->beginTransaction();
        $this->pdo->exec("INSERT INTO items VALUES (2, 'temporary')");
        $result = $this->builder()->select('items', ['value'])->orderBy('id')->cache(300)->execute();

        self::assertSame([['value' => 'confirmed'], ['value' => 'temporary']], $this->rows($result->data));
        self::assertSame(0, $this->cache->sets);

        $this->connection->rollBack();
        self::assertSame([['value' => 'confirmed']], $this->rows($this->builder()->select('items', ['value'])->orderBy('id')->cache(300)->execute()->data));
        self::assertSame(1, $this->cache->sets);
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testStreamingSelectIsNotCachedDuringTransactionAndStillStreams(): void
    {
        $streamConnection = new PDOConnection(new DatabaseSettings('sqlite', '', ':memory:', 0, '', ''));
        try {
            $streamPdo = $streamConnection->pdo(false);
            $streamPdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
            $streamPdo->exec("INSERT INTO items VALUES (1, 'streamed')");
            $streamCache = new TransactionAwareTestCache();
            $streamBuilder = new QueryBuilder($streamConnection, cache: $streamCache);
            $streamPdo->beginTransaction();

            $result = $streamBuilder->select('items', ['value'])->cache(300)->execute(false);

            self::assertSame([['value' => 'streamed']], iterator_to_array($result->data, false));
            self::assertSame(0, $streamCache->lookups);
            self::assertSame(0, $streamCache->sets);
            $streamPdo->rollBack();
        } finally {
            $streamConnection->disconnect();
        }
    }

    public function testCacheHitRemainsAvailableAfterTransactionCompletion(): void
    {
        $this->connection->beginTransaction();
        $this->connection->commit();

        $this->read();
        $this->read();

        self::assertSame(2, $this->cache->lookups);
        self::assertSame(1, $this->cache->hits);
        self::assertSame(1, $this->cache->sets);
    }

    private function read(): \Omegaalfa\QueryBuilder\QueryResultDTO
    {
        return $this->builder()->select('items', ['value'])->where('id', '=', 1)->cache(300)->execute();
    }

    private function builder(): QueryBuilder
    {
        return new QueryBuilder($this->connection, cache: $this->cache);
    }

    /** @param iterable<mixed> $rows
     * @return list<mixed>
     */
    private function rows(iterable $rows): array
    {
        return is_array($rows) ? $rows : iterator_to_array($rows, false);
    }
}

final class TransactionAwareTestCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $entries = [];

    /** @var array<string, list<string>> */
    private array $tagsByKey = [];

    public int $lookups = 0;
    public int $hits = 0;
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

    public function set(string $key, mixed $value, int $ttl): void
    {
        $this->entries[$key] = $value;
    }

    /** @param list<string> $tags */
    public function setWithTags(string $key, mixed $value, int $ttl, array $tags): void
    {
        $this->sets++;
        $this->entries[$key] = $value;
        $this->tagsByKey[$key] = $tags;
    }

    /** @param list<string> $tags */
    public function flushTags(array $tags): bool
    {
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