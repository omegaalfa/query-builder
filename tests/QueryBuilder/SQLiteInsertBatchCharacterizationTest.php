<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\Exceptions\QueryException;
use Omegaalfa\QueryBuilder\Interfaces\CacheInterface;
use Omegaalfa\QueryBuilder\Interfaces\ConnectionInterface;
use Omegaalfa\QueryBuilder\Interfaces\QueryLoggerInterface;
use Omegaalfa\QueryBuilder\QueryBuilder;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Throwable;

final class SQLiteInsertBatchCharacterizationTest extends TestCase
{
    public function testOneRowBatchGeneratesOneInsertStatement(): void
    {
        [$builder] = $this->createBuilder();

        $sql = $builder->insertBatch('batch_items', [['name' => 'one']])->getQuerySql();

        self::assertSame(
            'INSERT INTO "batch_items" ("name") VALUES (:param0)',
            $sql,
        );
    }

    public function testMultipleRowsUseSequentialPlaceholdersInInputOrder(): void
    {
        [$builder, $pdo, $logger] = $this->createBuilderWithLogger();

        $sql = $builder->insertBatch('batch_items', [
            ['name' => 'first', 'amount' => 10],
            ['name' => 'second', 'amount' => 20],
            ['name' => 'third', 'amount' => 30],
        ])->getQuerySql();

        self::assertSame(
            'INSERT INTO "batch_items" ("name", "amount") VALUES '
            . '(:param0, :param1), (:param2, :param3), (:param4, :param5)',
            $sql,
        );

        $result = $builder->execute();
        self::assertSame(3, $result->count);
        self::assertSame([
            ':param0' => 'first',
            ':param1' => 10,
            ':param2' => 'second',
            ':param3' => 20,
            ':param4' => 'third',
            ':param5' => 30,
        ], $logger->queries[0]['params']);
        self::assertSame([
            ['name' => 'first', 'amount' => 10],
            ['name' => 'second', 'amount' => 20],
            ['name' => 'third', 'amount' => 30],
        ], $pdo->query('SELECT name, amount FROM batch_items ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testSqlInspectionMethodsDoNotConsumeBuilderState(): void
    {
        [$builder] = $this->createBuilder();
        $sql = $builder->insertBatch('batch_items', [
            ['name' => "O'Reilly", 'amount' => 12],
            ['name' => 'second', 'amount' => 24],
        ])->getQuerySql();

        self::assertSame($sql, $builder->getQuerySql());
        self::assertSame(
            'INSERT INTO "batch_items" ("name", "amount") VALUES '
            . '(\'O\\\'Reilly\', 12), (\'second\', 24)',
            $builder->toSql(true),
        );
        self::assertSame($sql, $builder->getQuerySql());
        self::assertIsArray($builder->explain());
        self::assertSame($sql, $builder->getQuerySql());

        $builder->execute();
        self::assertSame('', $builder->getQuerySql());
    }

    public function testResultCountAndInsertIdReflectCurrentExecution(): void
    {
        [$builder] = $this->createBuilder();
        $result = $builder->insertBatch('batch_items', [
            ['name' => 'first'],
            ['name' => 'second'],
            ['name' => 'third'],
        ])->execute();

        self::assertSame(3, $result->count);
        self::assertFalse($result->insertId);

        $withId = $builder->insertBatch('batch_items', [
            ['name' => 'fourth'],
            ['name' => 'fifth'],
        ])->returnLastInsertId()->execute();

        self::assertSame(2, $withId->count);
        self::assertSame('5', $withId->insertId);
    }

    public function testConstraintFailureLeavesStatementAtomicAndBuilderInspectable(): void
    {
        [$builder, , $pdo] = $this->createBuilder(withUniqueName: true);
        $sql = $builder->insertBatch('batch_items', [
            ['name' => 'duplicate'],
            ['name' => 'duplicate'],
        ])->getQuerySql();

        try {
            $builder->execute();
            self::fail('A duplicated unique value must fail the batch statement.');
        } catch (QueryException $exception) {
            self::assertInstanceOf(PDOException::class, $exception->getPrevious());
        }

        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM batch_items')->fetchColumn());
        self::assertSame($sql, $builder->getQuerySql());
        self::assertStringContainsString('duplicate', $builder->toSql(true));
    }

    public function testBuilderCanBeReusedAfterSuccessAndAfterFailure(): void
    {
        [$builder, , $pdo] = $this->createBuilder(withUniqueName: true);
        $first = $builder->insertBatch('batch_items', [['name' => 'first']])->execute();

        self::assertSame(1, $first->count);
        self::assertSame('', $builder->getQuerySql());

        try {
            $builder->insertBatch('batch_items', [
                ['name' => 'duplicate'],
                ['name' => 'duplicate'],
            ])->execute();
            self::fail('The duplicated values should fail.');
        } catch (QueryException) {
            self::assertStringContainsString('duplicate', $builder->toSql(true));
        }

        $third = $builder->insertBatch('batch_items', [['name' => 'after-failure']])->execute();
        self::assertSame(1, $third->count);
        self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM batch_items')->fetchColumn());
        self::assertSame('', $builder->getQuerySql());
    }

    public function testWritesAreNotCachedAndLoggerObservesEachBatchExecution(): void
    {
        [$connection, $pdo] = $this->createConnection();
        $cache = new class implements CacheInterface {
            /** @var array<string, mixed> */
            public array $entries = [];
            public int $hasCalls = 0;

            public function has(string $key): bool { ++$this->hasCalls; return isset($this->entries[$key]); }
            public function get(string $key): mixed { return $this->entries[$key] ?? null; }
            public function set(string $key, mixed $value, int $ttl): void { $this->entries[$key] = $value; }
            public function delete(string $key): void { unset($this->entries[$key]); }
            public function deletePattern(string $pattern): bool { return true; }
            public function clear(): bool { $this->entries = []; return true; }
            public function getMultiple(array $keys): array { return []; }
        };
        $logger = new class implements QueryLoggerInterface {
            /** @var list<array{sql: string, params: array<string, mixed>, rowCount: int}> */
            public array $queries = [];
            /** @var list<array{sql: string, params: array<string, mixed>, error: Throwable}> */
            public array $errors = [];

            public function logQuery(string $sql, array $params, float $duration, int $rowCount): void
            {
                $this->queries[] = ['sql' => $sql, 'params' => $params, 'rowCount' => $rowCount];
            }

            public function logError(string $sql, array $params, Throwable $error): void
            {
                $this->errors[] = ['sql' => $sql, 'params' => $params, 'error' => $error];
            }
        };
        $builder = new QueryBuilder($connection, cache: $cache, logger: $logger);

        $result = $builder->insertBatch('batch_items', [
            ['name' => 'first', 'amount' => 1],
            ['name' => 'second', 'amount' => 2],
        ])->cache(60)->execute();

        self::assertSame(2, $result->count);
        self::assertSame(0, $cache->hasCalls);
        self::assertSame([], $cache->entries);
        self::assertCount(1, $logger->queries);
        self::assertSame(2, $logger->queries[0]['rowCount']);
        self::assertSame([
            ':param0' => 'first',
            ':param1' => 1,
            ':param2' => 'second',
            ':param3' => 2,
        ], $logger->queries[0]['params']);
        self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM batch_items')->fetchColumn());

        $repeatedBatch = $builder->insertBatch('batch_items', [
            ['name' => 'first', 'amount' => 1],
            ['name' => 'second', 'amount' => 2],
        ])->execute();

        self::assertSame(2, $repeatedBatch->count);
        self::assertSame(4, (int) $pdo->query('SELECT COUNT(*) FROM batch_items')->fetchColumn());
        self::assertSame(0, $cache->hasCalls);
        self::assertSame([], $cache->entries);
        self::assertCount(2, $logger->queries);
        self::assertSame(2, $logger->queries[1]['rowCount']);
        self::assertSame($logger->queries[0]['params'], $logger->queries[1]['params']);
        self::assertSame([], $logger->errors);
    }

    public function testExternalTransactionRemainsOpenUntilCallerRollsItBack(): void
    {
        [$builder, $connection, $pdo] = $this->createBuilder();
        $connection->beginTransaction();

        $builder->insertBatch('batch_items', [
            ['name' => 'first'],
            ['name' => 'second'],
        ])->execute();

        self::assertTrue($pdo->inTransaction());
        self::assertSame(1, $connection->getTransactionLevel());
        $connection->rollBack();
        self::assertFalse($pdo->inTransaction());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM batch_items')->fetchColumn());
    }

    public function testEmptyBatchKeepsPreviousBuilderQueryWhileMismatchedRowsResetSql(): void
    {
        [$builder] = $this->createBuilder();
        $previousSql = $builder->insert('batch_items', ['name' => 'pending'])->getQuerySql();

        try {
            $builder->insertBatch('batch_items', []);
            self::fail('An empty batch must be rejected.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('at least one row', $exception->getMessage());
        }

        self::assertSame($previousSql, $builder->getQuerySql());

        try {
            $builder->insertBatch('batch_items', [
                ['name' => 'first', 'amount' => 1],
                ['name' => 'second'],
            ]);
            self::fail('Rows with different columns must be rejected.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('index 1', $exception->getMessage());
        }

        self::assertSame('', $builder->getQuerySql());
    }

    public function testBatchAboveControlledParameterLimitIsSplitAndClearsState(): void
    {
        [$innerPdo, $limitedConnection] = $this->createLimitedConnection(maxVariables: 3);
        $builder = new QueryBuilder($limitedConnection);
        $sql = $builder->insertBatch('batch_items', [
            ['name' => 'first'],
            ['name' => 'second'],
            ['name' => 'third'],
            ['name' => 'fourth'],
        ])->getQuerySql();

        $result = $builder->execute();

        self::assertSame(4, $result->count);
        self::assertSame(4, (int) $innerPdo->query('SELECT COUNT(*) FROM batch_items')->fetchColumn());
        self::assertSame('', $builder->getQuerySql());
        self::assertSame(4, substr_count($sql, ':param'));
    }

    public function testBatchFailsClearlyWhenOneRowExceedsControlledParameterLimit(): void
    {
        [$innerPdo, $limitedConnection] = $this->createLimitedConnection(maxVariables: 0);
        $builder = new QueryBuilder($limitedConnection);
        $sql = $builder->insertBatch('batch_items', [['name' => 'one']])->getQuerySql();

        try {
            $builder->execute();
            self::fail('A single row above the controlled limit must fail.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('cannot prepare one insertBatch row', $exception->getMessage());
            self::assertInstanceOf(PDOException::class, $exception->getPrevious());
            self::assertStringContainsString('too many SQL variables', $exception->getPrevious()->getMessage());
        }

        self::assertSame(0, (int) $innerPdo->query('SELECT COUNT(*) FROM batch_items')->fetchColumn());
        self::assertSame($sql, $builder->getQuerySql());
    }

    /** @return array{QueryBuilder, PDOConnection, PDO} */
    private function createBuilder(bool $withUniqueName = false): array
    {
        [$connection, $pdo] = $this->createConnection($withUniqueName);

        return [new QueryBuilder($connection), $connection, $pdo];
    }

    /** @return array{QueryBuilder, PDO, object} */
    private function createBuilderWithLogger(): array
    {
        [$connection, $pdo] = $this->createConnection();
        $logger = new class implements QueryLoggerInterface {
            /** @var list<array<string, mixed>> */
            public array $queries = [];
            public function logQuery(string $sql, array $params, float $duration, int $rowCount): void
            {
                $this->queries[] = compact('sql', 'params', 'rowCount');
            }
            public function logError(string $sql, array $params, Throwable $error): void {}
        };

        return [new QueryBuilder($connection, logger: $logger), $pdo, $logger];
    }

    /** @return array{PDOConnection, PDO} */
    private function createConnection(bool $withUniqueName = false): array
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not installed in this PHP runtime.');
        }

        $connection = new PDOConnection(new DatabaseSettings(
            driver: 'sqlite',
            host: '',
            database: ':memory:',
            port: 0,
            username: '',
            password: '',
        ));
        $pdo = $connection->pdo();
        $unique = $withUniqueName ? ' UNIQUE' : '';
        $pdo->exec('CREATE TABLE batch_items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL' . $unique . ', amount INTEGER)');

        return [$connection, $pdo];
    }

    /** @return array{PDO, ConnectionInterface} */
    private function createLimitedConnection(int $maxVariables): array
    {
        $innerPdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $innerPdo->exec('CREATE TABLE batch_items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, amount INTEGER)');
        $limitedPdo = new class($innerPdo, $maxVariables) extends PDO {
            public function __construct(
                private readonly PDO $inner,
                private readonly int $maxVariables,
            ) {
            }

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                preg_match_all('/:param\d+/', $query, $matches);
                if (count($matches[0]) > $this->maxVariables) {
                    throw new PDOException('SQLSTATE[HY000]: General error: 1 too many SQL variables');
                }

                return $this->inner->prepare($query, $options);
            }
        };
        $connection = new class($limitedPdo) implements ConnectionInterface {
            public function __construct(private readonly PDO $pdo)
            {
            }

            public function connect(): void {}
            public function pdo(bool $bufferedQuery = true): PDO { return $this->pdo; }
            public function disconnect(): void {}
            public function transaction(callable $callback): mixed { return $callback($this->pdo); }
            public function getDriver(): string { return 'sqlite'; }
        };

        return [$innerPdo, $connection];
    }
}
