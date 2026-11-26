<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\Interfaces\CacheInterface;
use Omegaalfa\QueryBuilder\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Throwable;

final class SQLiteCteLifecycleTest extends TestCase
{
    private PDOConnection $connection;
    private \PDO $pdo;
    private CteLifecycleCache $cache;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }

        $this->connection = new PDOConnection(new DatabaseSettings('sqlite', '', ':memory:', 0, '', ''));
        $this->pdo = $this->connection->pdo();
        $this->pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, value TEXT)');
        $this->pdo->exec("INSERT INTO items VALUES (1, 'one'), (2, 'two')");
        $this->cache = new CteLifecycleCache();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->disconnect();
        }
    }

    public function testCteSelectCleansUpForTheNextSelectAndPreservesSqlBindings(): void
    {
        $builder = $this->builder()
            ->with('source', 'SELECT id, value FROM items WHERE id = 1')
            ->select('source', ['value'])
            ->where('id', '=', 1);

        self::assertSame(
            'WITH "source" AS (SELECT id, value FROM items WHERE id = 1) SELECT "value" FROM "source" WHERE "id" = :param0',
            $builder->getQuerySql(),
        );
        self::assertSame(
            'WITH "source" AS (SELECT id, value FROM items WHERE id = 1) SELECT "value" FROM "source" WHERE "id" = 1',
            $builder->toSql(true),
        );
        self::assertSame([['value' => 'one']], $this->rows($builder->execute()->data));

        $nextSql = $builder->select('items', ['value'])->where('id', '=', 2)->getQuerySql();
        self::assertSame('SELECT "value" FROM "items" WHERE "id" = :param0', $nextSql);
        self::assertSame([['value' => 'two']], $this->rows($builder->execute()->data));
    }

    public function testSelectThenWithAndMultipleCtesAreAppliedOnlyToTheirOperation(): void
    {
        $builder = $this->builder()
            ->select('second_source', ['value'])
            ->with('first_source', 'SELECT id, value FROM items WHERE id = ?', [1])
            ->with('second_source', 'SELECT value FROM first_source');

        $sql = $builder->getQuerySql();
        self::assertStringStartsWith('WITH "first_source" AS (', $sql);
        self::assertStringContainsString('), "second_source" AS (SELECT value FROM first_source)', $sql);
        self::assertSame([['value' => 'one']], $this->rows($builder->execute()->data));

        self::assertSame(
            'SELECT "value" FROM "items" WHERE "id" = :param0',
            $builder->select('items', ['value'])->where('id', '=', 2)->getQuerySql(),
        );
    }

    public function testCteInsertUpdateAndDeleteDoNotLeakIntoLaterOperations(): void
    {
        $insert = $this->builder()
            ->with('new_value', "SELECT 'three' AS value")
            ->insert('items', ['id' => 3, 'value' => 'three']);
        self::assertStringStartsWith('WITH "new_value" AS (', $insert->getQuerySql());
        self::assertSame(1, $insert->execute()->count);
        self::assertSame('three', $this->pdo->query('SELECT value FROM items WHERE id = 3')->fetchColumn());

        $update = $this->builder()
            ->with('update_source', 'SELECT 1')
            ->update('items', ['value' => 'changed'])
            ->where('id', '=', 1);
        self::assertStringStartsWith('WITH "update_source" AS (', $update->getQuerySql());
        self::assertSame(1, $update->execute()->count);

        $delete = $this->builder()
            ->with('delete_source', 'SELECT 1')
            ->delete('items')
            ->where('id', '=', 3);
        self::assertStringStartsWith('WITH "delete_source" AS (', $delete->getQuerySql());
        self::assertSame(1, $delete->execute()->count);

        self::assertSame(
            'SELECT "value" FROM "items" WHERE "id" = :param0',
            $delete->select('items', ['value'])->where('id', '=', 1)->getQuerySql(),
        );
        self::assertSame([['value' => 'changed']], $this->rows($delete->execute()->data));
    }

    public function testCteBypassesCacheThenNormalSelectCanMissAndHit(): void
    {
        $builder = $this->builder();
        $cteResult = $builder
            ->with('source', 'SELECT value FROM items WHERE id = 1')
            ->select('source', ['value'])
            ->cache(30)
            ->execute();

        self::assertSame([['value' => 'one']], $this->rows($cteResult->data));
        self::assertSame(0, $this->cache->lookups);
        self::assertSame(0, $this->cache->sets);

        $builder->select('items', ['value'])->where('id', '=', 1)->cache(30)->execute();
        $builder->select('items', ['value'])->where('id', '=', 1)->cache(30)->execute();
        self::assertSame(2, $this->cache->lookups);
        self::assertSame(1, $this->cache->hits);
        self::assertSame(1, $this->cache->sets);

        $nextSql = $builder->select('items', ['value'])->where('id', '=', 2)->getQuerySql();
        self::assertStringStartsNotWith('WITH ', $nextSql);
    }

    public function testFailedCteQueryRetainsCteForRetryAndClearsItAfterSuccess(): void
    {
        $builder = $this->builder()
            ->with('source', 'SELECT value FROM later_table')
            ->select('source', ['value']);

        try {
            $builder->execute();
            self::fail('The first execution should fail because later_table does not exist.');
        } catch (Throwable) {
            self::assertStringContainsString('WITH "source" AS (SELECT value FROM later_table)', $builder->getQuerySql());
        }

        $this->pdo->exec('CREATE TABLE later_table (value TEXT)');
        $this->pdo->exec("INSERT INTO later_table VALUES ('retried')");
        self::assertSame([['value' => 'retried']], $this->rows($builder->execute()->data));

        self::assertSame(
            'SELECT "value" FROM "items" WHERE "id" = :param0',
            $builder->select('items', ['value'])->where('id', '=', 2)->getQuerySql(),
        );
    }

    public function testCteDmlInvalidatesCacheAndBuilderReuseInsideTransactionIsClean(): void
    {
        $builder = $this->builder();
        $read = fn () => $builder->select('items', ['value'])->where('id', '=', 1)->cache(30)->execute();
        self::assertSame([['value' => 'one']], $this->rows($read()->data));

        $write = $builder
            ->with('target', 'SELECT 1')
            ->update('items', ['value' => 'after'])
            ->where('id', '=', 1);
        self::assertSame(1, $write->execute()->count);
        self::assertSame(1, $this->cache->invalidations);
        self::assertSame([['value' => 'after']], $this->rows($read()->data));

        $this->connection->beginTransaction();
        try {
            $transactionalWrite = $builder
                ->with('transaction_source', 'SELECT 1')
                ->update('items', ['value' => 'uncommitted'])
                ->where('id', '=', 1);
            self::assertSame(1, $transactionalWrite->execute()->count);

            $insideTransaction = $builder->select('items', ['value'])->where('id', '=', 1)->execute();
            self::assertSame([['value' => 'uncommitted']], $this->rows($insideTransaction->data));
            self::assertSame(
                'SELECT "value" FROM "items" WHERE "id" = :param0',
                $builder->select('items', ['value'])->where('id', '=', 1)->getQuerySql(),
            );
        } finally {
            $this->connection->rollBack();
        }

        self::assertSame([['value' => 'after']], $this->rows($read()->data));
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

final class CteLifecycleCache implements CacheInterface
{
    /** @var array<string, array{value: mixed, tags: list<string>}> */
    private array $entries = [];
    public int $lookups = 0;
    public int $hits = 0;
    public int $sets = 0;
    public int $invalidations = 0;

    public function has(string $key): bool
    {
        $this->lookups++;
        return isset($this->entries[$key]);
    }

    public function get(string $key): mixed
    {
        if (isset($this->entries[$key])) {
            $this->hits++;
            return $this->entries[$key]['value'];
        }
        return null;
    }

    public function set(string $key, mixed $value, int $ttl): void { $this->save($key, $value, []); }
    public function setWithTags(string $key, mixed $value, int $ttl, array $tags): void { $this->save($key, $value, $tags); }

    public function flushTags(array $tags): bool
    {
        $this->invalidations++;
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

    private function save(string $key, mixed $value, array $tags): void
    {
        $this->sets++;
        $this->entries[$key] = ['value' => $value, 'tags' => $tags];
    }
}