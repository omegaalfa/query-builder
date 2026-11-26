<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\Exceptions\QueryException;
use Omegaalfa\QueryBuilder\Interfaces\DatabaseValueInterface;
use Omegaalfa\QueryBuilder\Interfaces\QueryLoggerInterface;
use Omegaalfa\QueryBuilder\QueryBuilder;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

final class SQLitePositionalBatchOptimizationTest extends TestCase
{
    public function testOneThousandRowsKeepOrderCountAndFinalInsertId(): void
    {
        [$builder, $pdo] = $this->builder();
        $rows = array_map(static fn (int $id): array => ['id' => $id, 'value' => "value-{$id}"], range(1, 1000));

        $result = $builder->insertBatch('items', $rows)->returnLastInsertId()->execute();

        self::assertSame(1000, $result->count);
        self::assertSame('1000', $result->insertId);
        self::assertSame($rows, $pdo->query('SELECT id, value FROM items ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testActualSQLiteSqlUsesPositionalBindingsButLoggerKeepsNamedDiagnostics(): void
    {
        [$connection, $pdo] = $this->connection();
        $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
        $logger = new PositionalBatchLogger();
        $builder = new QueryBuilder($connection, logger: $logger);

        $inspectionSql = $builder->insertBatch('items', [
            ['id' => 1, 'value' => 'one'],
            ['id' => 2, 'value' => 'two'],
        ])->getQuerySql();
        self::assertStringContainsString(':param0', $inspectionSql);

        $builder->execute();

        self::assertCount(1, $logger->queries);
        self::assertStringContainsString('?', $logger->queries[0]['sql']);
        self::assertSame([
            ':param0' => 1,
            ':param1' => 'one',
            ':param2' => 2,
            ':param3' => 'two',
        ], $logger->queries[0]['params']);
    }

    public function testSupportedValueTypesAndRepeatedValuesArePreserved(): void
    {
        [$builder, $pdo] = $this->builder();
        $pdo->exec('CREATE TABLE typed_items (id INTEGER PRIMARY KEY, value TEXT, amount REAL, enabled INTEGER, note TEXT)');
        $value = new class implements DatabaseValueInterface {
            public function value(): mixed { return 'special'; }
            public function pdoType(): int { return PDO::PARAM_STR; }
            public function sqlType(): ?string { return null; }
            public function supportsDriver(string $driver): bool { return $driver === 'sqlite'; }
            public function cacheValue(): string { return 'special'; }
        };
        $rows = [
            ['id' => 1, 'value' => 'same', 'amount' => 1.5, 'enabled' => true, 'note' => null],
            ['id' => 2, 'value' => 'same', 'amount' => 2.5, 'enabled' => false, 'note' => $value],
        ];

        $builder->insertBatch('typed_items', $rows)->execute();

        self::assertSame([
            ['id' => 1, 'value' => 'same', 'amount' => 1.5, 'enabled' => 1, 'note' => null],
            ['id' => 2, 'value' => 'same', 'amount' => 2.5, 'enabled' => 0, 'note' => 'special'],
        ], $pdo->query('SELECT id, value, amount, enabled, note FROM typed_items ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testFailureInMiddleChunkRollsBackTheEntireBatch(): void
    {
        [$builder, $pdo] = $this->builder(uniqueValue: true);
        $rows = array_map(static fn (int $id): array => ['id' => $id, 'value' => "value-{$id}"], range(1, 900));
        $rows[] = ['id' => 1, 'value' => 'duplicate'];

        $this->expectException(QueryException::class);
        try {
            $builder->insertBatch('items', $rows)->execute();
        } finally {
            self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM items')->fetchColumn());
        }
    }

    public function testBatchInsideExternalTransactionLeavesOuterTransactionOpen(): void
    {
        [$connection, $pdo] = $this->connection();
        $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
        $builder = new QueryBuilder($connection);
        $connection->beginTransaction();

        $result = $builder->insertBatch('items', [['id' => 1, 'value' => 'inside']])->execute();

        self::assertSame(1, $result->count);
        self::assertTrue($pdo->inTransaction());
        self::assertSame(1, $connection->getTransactionLevel());
        $connection->rollBack();
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM items')->fetchColumn());
    }

    public function testNestedSavepointCanRollbackBatchWithoutClosingOuterTransaction(): void
    {
        [$connection, $pdo] = $this->connection();
        $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
        $builder = new QueryBuilder($connection);
        $connection->beginTransaction();
        $connection->beginTransaction();

        $builder->insertBatch('items', [['id' => 1, 'value' => 'nested']])->execute();
        $connection->rollBack();

        self::assertTrue($pdo->inTransaction());
        self::assertSame(1, $connection->getTransactionLevel());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM items')->fetchColumn());
        $connection->rollBack();
    }

    public function testEmptyAndMismatchedBatchesRetainExistingValidation(): void
    {
        [$builder] = $this->builder();

        try {
            $builder->insertBatch('items', []);
            self::fail('An empty batch must fail.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('at least one row', $exception->getMessage());
        }

        $this->expectException(QueryException::class);
        $builder->insertBatch('items', [['id' => 1, 'value' => 'one'], ['id' => 2]])->execute();
    }

    public function testNonSQLiteBuildersKeepNamedPlaceholders(): void
    {
        foreach (['mysql', 'pgsql'] as $driver) {
            $builder = new QueryBuilder(new class($driver) implements \Omegaalfa\QueryBuilder\Interfaces\ConnectionInterface {
                public function __construct(private readonly string $driver) {}
                public function connect(): void {}
                public function pdo(bool $bufferedQuery = true): PDO { throw new \LogicException('not executed'); }
                public function disconnect(): void {}
                public function transaction(callable $callback): mixed { return $callback(null); }
                public function getDriver(): string { return $this->driver; }
            });
            $sql = $builder->insertBatch('items', [['id' => 1, 'value' => 'one']])->getQuerySql();

            self::assertStringContainsString(':param0', $sql);
            self::assertStringNotContainsString('VALUES (?, ?)', $sql);
        }
    }

    /** @return array{QueryBuilder, PDO} */
    private function builder(bool $uniqueValue = false): array
    {
        [$connection, $pdo] = $this->connection();
        $unique = $uniqueValue ? ' UNIQUE' : '';
        $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, value TEXT NOT NULL' . $unique . ')');
        return [new QueryBuilder($connection), $pdo];
    }

    /** @return array{PDOConnection, PDO} */
    private function connection(): array
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not installed.');
        }
        $connection = new PDOConnection(new DatabaseSettings('sqlite', '', ':memory:', 0, '', ''));
        return [$connection, $connection->pdo()];
    }
}

final class PositionalBatchLogger implements QueryLoggerInterface
{
    /** @var list<array{sql: string, params: array<string, mixed>}> */
    public array $queries = [];

    public function logQuery(string $sql, array $params, float $duration, int $rowCount): void
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params];
    }

    public function logError(string $sql, array $params, Throwable $error): void {}
}
