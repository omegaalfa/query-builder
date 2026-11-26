<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\Exceptions\DatabaseException;
use Omegaalfa\QueryBuilder\Exceptions\QueryException;
use Omegaalfa\QueryBuilder\Interfaces\ConnectionInterface;
use Omegaalfa\QueryBuilder\QueryBuilder;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SQLiteBatchTransactionIntegrityAuditTest extends TestCase
{
    public function testConstraintFailureInThirdChunkRollsBackTheWholeBatch(): void
    {
        [$builder, $connection, $pdo] = $this->createBuilder(unique: true);
        $rows = array_map(static fn (int $i): array => ['value' => "row-{$i}"], range(1, 1799));
        $rows[] = ['value' => 'row-1'];

        try {
            $builder->insertBatch('audit_items', $rows)->execute();
            self::fail('The duplicate in chunk three should fail.');
        } catch (QueryException $exception) {
            self::assertInstanceOf(PDOException::class, $exception->getPrevious());
        }

        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM audit_items')->fetchColumn());
        self::assertFalse($pdo->inTransaction());
        self::assertSame(0, $connection->getTransactionLevel());
    }

    public function testManagedOuterTransactionKeepsEarlierAndLaterOperationsAfterBatchFailure(): void
    {
        [$builder, $connection, $pdo] = $this->createBuilder(unique: true);
        $connection->beginTransaction();
        $pdo->exec("INSERT INTO audit_items (value) VALUES ('before')");
        $rows = array_map(static fn (int $i): array => ['value' => "row-{$i}"], range(1, 900));
        $rows[] = ['value' => 'before'];

        try {
            $builder->insertBatch('audit_items', $rows)->execute();
            self::fail('The batch should hit a unique constraint in its second chunk.');
        } catch (QueryException) {
            self::assertTrue($pdo->inTransaction());
            self::assertSame(1, $connection->getTransactionLevel());
        }

        $pdo->exec("INSERT INTO audit_items (value) VALUES ('after')");
        $connection->commit();
        self::assertSame(['before', 'after'], $this->values($pdo));
        self::assertFalse($pdo->inTransaction());
        self::assertSame(0, $connection->getTransactionLevel());
    }

    public function testRawPdoOuterTransactionKeepsEarlierAndLaterOperationsAfterBatchFailure(): void
    {
        [$builder, $connection, $pdo] = $this->createBuilder(unique: true);
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO audit_items (value) VALUES ('before')");
        $rows = array_map(static fn (int $i): array => ['value' => "row-{$i}"], range(1, 900));
        $rows[] = ['value' => 'before'];

        try {
            $builder->insertBatch('audit_items', $rows)->execute();
            self::fail('The batch should fail and roll back to its savepoint.');
        } catch (QueryException) {
            self::assertTrue($pdo->inTransaction());
            self::assertSame(0, $connection->getTransactionLevel());
        }

        $pdo->exec("INSERT INTO audit_items (value) VALUES ('after')");
        $pdo->commit();
        self::assertSame(['before', 'after'], $this->values($pdo));
        self::assertFalse($pdo->inTransaction());
    }

    public function testRawPdoTransactionStartedAfterBuilderConstructionIsDetected(): void
    {
        [$builder, $connection, $pdo] = $this->createBuilder();
        $pdo->beginTransaction();
        $rows = array_map(static fn (int $i): array => ['value' => "row-{$i}"], range(1, 901));

        $result = $builder->insertBatch('audit_items', $rows)->execute();

        self::assertSame(901, $result->count);
        self::assertTrue($pdo->inTransaction());
        self::assertSame(0, $connection->getTransactionLevel());
        self::assertSame(901, (int) $pdo->query('SELECT COUNT(*) FROM audit_items')->fetchColumn());
        $pdo->rollBack();
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM audit_items')->fetchColumn());
        self::assertFalse($pdo->inTransaction());
    }

    public function testOuterRollbackUndoesAllChunksAfterSuccessfulBatch(): void
    {
        [$builder, $connection, $pdo] = $this->createBuilder();

        try {
            $connection->transaction(function (PDO $pdo) use ($builder): void {
                $pdo->exec("INSERT INTO audit_items (value) VALUES ('outer')");
                $builder->insertBatch('audit_items', array_map(
                    static fn (int $i): array => ['value' => "row-{$i}"],
                    range(1, 901),
                ))->execute();
                throw new RuntimeException('rollback outer transaction');
            });
            self::fail('The outer transaction should roll back.');
        } catch (DatabaseException $exception) {
            self::assertInstanceOf(RuntimeException::class, $exception->getPreviousException());
        }

        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM audit_items')->fetchColumn());
        self::assertFalse($pdo->inTransaction());
        self::assertSame(0, $connection->getTransactionLevel());
    }

    public function testSavepointCreationFailureLeavesManagedOuterTransactionUntouched(): void
    {
        [$builder, $connection, $pdo, $inner] = $this->createFaultInjectingBuilder('create');
        $connection->beginTransaction();
        $inner->exec("INSERT INTO audit_items (value) VALUES ('before')");

        try {
            $builder->insertBatch('audit_items', $this->rows(901))->execute();
            self::fail('The injected savepoint creation error should escape.');
        } catch (DatabaseException $exception) {
            self::assertStringContainsString('Failed to create savepoint', $exception->getMessage());
            self::assertInstanceOf(PDOException::class, $exception->getPreviousException());
        }

        self::assertSame(['before'], $this->values($inner));
        self::assertTrue($pdo->inTransaction());
        self::assertSame(1, $connection->getTransactionLevel());
        self::assertStringContainsString(':param900', $builder->getQuerySql());
        $connection->commit();
    }

    public function testSavepointReleaseFailureRollsBatchBackAndPreservesOuterState(): void
    {
        [$builder, $connection, $pdo, $inner] = $this->createFaultInjectingBuilder('release');
        $connection->beginTransaction();
        $inner->exec("INSERT INTO audit_items (value) VALUES ('before')");

        try {
            $builder->insertBatch('audit_items', $this->rows(901))->execute();
            self::fail('The injected savepoint release error should escape.');
        } catch (DatabaseException $exception) {
            self::assertStringContainsString('Transaction failed:', $exception->getMessage());
            self::assertInstanceOf(DatabaseException::class, $exception->getPreviousException());
        }

        self::assertSame(['before'], $this->values($inner));
        self::assertTrue($pdo->inTransaction());
        self::assertSame(1, $connection->getTransactionLevel());
        $inner->exec("INSERT INTO audit_items (value) VALUES ('after')");
        $connection->commit();
        self::assertSame(['before', 'after'], $this->values($inner));
    }

    public function testSavepointRollbackFailureLeavesPartialRowsAndLosesOriginalConstraintException(): void
    {
        [$builder, $connection, $pdo, $inner] = $this->createFaultInjectingBuilder('rollback', unique: true);
        $connection->beginTransaction();
        $inner->exec("INSERT INTO audit_items (value) VALUES ('existing')");
        $rows = $this->rows(900);
        $rows[] = ['value' => 'existing'];

        try {
            $builder->insertBatch('audit_items', $rows)->execute();
            self::fail('The batch constraint and injected rollback errors should escape.');
        } catch (DatabaseException $exception) {
            self::assertStringContainsString('Failed to rollback transaction', $exception->getMessage());
            self::assertInstanceOf(PDOException::class, $exception->getPreviousException());
            self::assertStringContainsString('injected rollback failure', $exception->getPreviousException()->getMessage());
        }

        self::assertTrue($pdo->inTransaction());
        self::assertSame(2, $connection->getTransactionLevel());
        self::assertSame(901, (int) $inner->query('SELECT COUNT(*) FROM audit_items')->fetchColumn());
        self::assertTrue($connection->rollBack());
        self::assertSame(1, $connection->getTransactionLevel());
        self::assertSame(['existing'], $this->values($inner));
        self::assertTrue($connection->rollBack());
        self::assertSame(0, $connection->getTransactionLevel());
        self::assertFalse($pdo->inTransaction());
    }

    public function testConsumerSavepointNameCollisionDoesNotLeaveNestedSavepointPending(): void
    {
        [$builder, $connection, $pdo] = $this->createBuilder(unique: true);
        $connection->beginTransaction();
        $pdo->exec('SAVEPOINT SAVEPOINT_1');
        $pdo->exec("INSERT INTO audit_items (value) VALUES ('consumer-op')");
        $rows = $this->rows(900);
        $rows[] = ['value' => 'row-1'];

        try {
            $builder->insertBatch('audit_items', $rows)->execute();
            self::fail('The duplicate should fail in the second chunk.');
        } catch (QueryException) {
            self::assertSame(1, $connection->getTransactionLevel());
        }

        $pdo->exec("INSERT INTO audit_items (value) VALUES ('after-batch')");
        $pdo->exec('ROLLBACK TO SAVEPOINT SAVEPOINT_1');
        self::assertSame([], $this->values($pdo));
        $connection->commit();
        self::assertSame([], $this->values($pdo));
    }

    public function testConnectionCanBeReusedForSuccessfulBatchAfterConstraintFailure(): void
    {
        [$builder, $connection, $pdo] = $this->createBuilder(unique: true);
        $rows = $this->rows(900);
        $rows[] = ['value' => 'row-1'];

        try {
            $builder->insertBatch('audit_items', $rows)->execute();
            self::fail('The first batch should fail.');
        } catch (QueryException) {
            self::assertFalse($pdo->inTransaction());
            self::assertSame(0, $connection->getTransactionLevel());
        }

        $result = $builder->insertBatch('audit_items', [['value' => 'valid']])->execute();
        self::assertSame(1, $result->count);
        self::assertSame(['valid'], $this->values($pdo));
        self::assertFalse($pdo->inTransaction());
        self::assertSame(0, $connection->getTransactionLevel());
    }

    /** @return array{QueryBuilder, PDOConnection, PDO, PDO} */
    private function createFaultInjectingBuilder(string $fault, bool $unique = false): array
    {
        $inner = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->createTable($inner, $unique);
        $wrapped = new SQLiteBatchFaultInjectingPDO($inner, $fault);
        $connection = new PDOConnection($this->settings());
        $property = new \ReflectionProperty(PDOConnection::class, 'connection');
        $property->setValue($connection, $wrapped);

        return [new QueryBuilder($connection), $connection, $wrapped, $inner];
    }

    /** @return array{QueryBuilder, PDOConnection, PDO} */
    private function createBuilder(bool $unique = false): array
    {
        $connection = new PDOConnection($this->settings());
        $pdo = $connection->pdo();
        $this->createTable($pdo, $unique);
        return [new QueryBuilder($connection), $connection, $pdo];
    }

    private function settings(): DatabaseSettings
    {
        return new DatabaseSettings(
            driver: 'sqlite',
            host: '',
            database: ':memory:',
            port: 0,
            username: '',
            password: '',
        );
    }

    private function createTable(PDO $pdo, bool $unique): void
    {
        $pdo->exec('CREATE TABLE audit_items (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT NOT NULL'
            . ($unique ? ' UNIQUE' : '') . ')');
    }

    /** @return list<array{value: string}> */
    private function rows(int $count): array
    {
        return array_map(static fn (int $i): array => ['value' => "row-{$i}"], range(1, $count));
    }

    /** @return list<string> */
    private function values(PDO $pdo): array
    {
        return $pdo->query('SELECT value FROM audit_items ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    }
}

final class SQLiteBatchFaultInjectingPDO extends PDO
{
    private bool $faultTriggered = false;

    public function __construct(private readonly PDO $inner, private readonly string $fault)
    {
    }

    public function beginTransaction(): bool { return $this->inner->beginTransaction(); }
    public function commit(): bool { return $this->inner->commit(); }
    public function rollBack(): bool { return $this->inner->rollBack(); }
    public function inTransaction(): bool { return $this->inner->inTransaction(); }
    public function lastInsertId(?string $name = null): string|false { return $this->inner->lastInsertId($name); }
    public function prepare(string $query, array $options = []): PDOStatement|false { return $this->inner->prepare($query, $options); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return $this->inner->query($query, ...($fetchMode === null ? [] : [$fetchMode, ...$fetchModeArgs]));
    }

    public function exec(string $statement): int|false
    {
        $trigger = match ($this->fault) {
            'create' => str_starts_with($statement, 'SAVEPOINT SAVEPOINT_'),
            'release' => str_starts_with($statement, 'RELEASE SAVEPOINT SAVEPOINT_'),
            'rollback' => str_starts_with($statement, 'ROLLBACK TO SAVEPOINT SAVEPOINT_'),
            default => false,
        };
        if ($trigger && !$this->faultTriggered) {
            $this->faultTriggered = true;
            throw new PDOException('injected ' . $this->fault . ' failure');
        }
        return $this->inner->exec($statement);
    }
}
