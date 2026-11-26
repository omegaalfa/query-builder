<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\Exceptions\DatabaseException;
use Omegaalfa\QueryBuilder\Exceptions\QueryException;
use Omegaalfa\QueryBuilder\Internal\RollbackFailureContext;
use Omegaalfa\QueryBuilder\QueryBuilder;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class SQLiteBatchRollbackFailureTest extends TestCase
{
    public function testConstraintFailureWithSuccessfulRollbackLeavesNoRowsOrTransaction(): void
    {
        [$builder, $connection, $pdo] = $this->createBuilder(unique: true);
        $rows = $this->rows(900);
        $rows[] = ['value' => 'row-1'];

        try {
            $builder->insertBatch('batch_items', $rows)->execute();
            self::fail('The duplicate in the later chunk should fail.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('UNIQUE constraint failed', $exception->getMessage());
            self::assertInstanceOf(PDOException::class, $exception->getPrevious());
        }

        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM batch_items')->fetchColumn());
        self::assertSame(0, $connection->getTransactionLevel());
        self::assertFalse($pdo->inTransaction());
    }

    public function testConstraintAndRollbackToFailuresAreBothAccessibleAndStateRemainsUncertain(): void
    {
        [$builder, $connection, $pdo, $inner] = $this->createFaultInjectingBuilder('rollback', unique: true);
        $connection->beginTransaction();
        $inner->exec("INSERT INTO batch_items (value) VALUES ('outer-before')");
        $rows = $this->rows(900);
        $rows[] = ['value' => 'outer-before'];

        try {
            $builder->insertBatch('batch_items', $rows)->execute();
            self::fail('The constraint and rollback injection should both be reported.');
        } catch (DatabaseException $exception) {
            self::assertStringContainsString('rollback was not confirmed', $exception->getMessage());
            self::assertStringContainsString('partial changes', $exception->getMessage());
            $context = $exception->getPreviousException();
            self::assertInstanceOf(RollbackFailureContext::class, $context);
            self::assertInstanceOf(QueryException::class, $context->getOriginalFailure());
            self::assertStringContainsString('UNIQUE constraint failed', $context->getOriginalFailure()->getMessage());
            self::assertInstanceOf(
                PDOException::class,
                $context->getOriginalFailure()->getPrevious(),
            );
            self::assertInstanceOf(DatabaseException::class, $context->getRollbackFailure());
            self::assertInstanceOf(PDOException::class, $context->getRollbackFailure()->getPreviousException());
            self::assertStringContainsString(
                'injected ROLLBACK TO failure',
                $context->getRollbackFailure()->getPreviousException()->getMessage(),
            );
        }

        self::assertTrue($pdo->inTransaction());
        self::assertSame(2, $connection->getTransactionLevel());
        self::assertSame(901, (int) $inner->query('SELECT COUNT(*) FROM batch_items')->fetchColumn());

        // The consumer explicitly retries the nested rollback, then decides the outer transaction.
        self::assertTrue($connection->rollBack());
        self::assertSame(1, $connection->getTransactionLevel());
        self::assertSame(['outer-before'], $this->values($inner));
        self::assertTrue($connection->rollBack());
        self::assertSame(0, $connection->getTransactionLevel());
        self::assertFalse($pdo->inTransaction());
        self::assertSame([], $this->values($inner));
    }

    public function testConstraintAndReleaseFailuresAreBothAccessibleAndDepthIsNotReduced(): void
    {
        [$builder, $connection, $pdo, $inner] = $this->createFaultInjectingBuilder('release', unique: true);
        $connection->beginTransaction();
        $inner->exec("INSERT INTO batch_items (value) VALUES ('outer-before')");
        $rows = $this->rows(900);
        $rows[] = ['value' => 'outer-before'];

        try {
            $builder->insertBatch('batch_items', $rows)->execute();
            self::fail('The constraint and RELEASE injection should both be reported.');
        } catch (DatabaseException $exception) {
            self::assertStringContainsString('rollback was not confirmed', $exception->getMessage());
            $context = $exception->getPreviousException();
            self::assertInstanceOf(RollbackFailureContext::class, $context);
            self::assertInstanceOf(QueryException::class, $context->getOriginalFailure());
            self::assertStringContainsString('UNIQUE constraint failed', $context->getOriginalFailure()->getMessage());
            self::assertInstanceOf(DatabaseException::class, $context->getRollbackFailure());
            self::assertStringContainsString(
                'injected RELEASE failure',
                $context->getRollbackFailure()->getPreviousException()?->getMessage() ?? '',
            );
        }

        self::assertSame(2, $connection->getTransactionLevel());
        self::assertTrue($pdo->inTransaction());
        self::assertSame(['outer-before'], $this->values($inner));

        self::assertTrue($connection->rollBack());
        self::assertSame(1, $connection->getTransactionLevel());
        self::assertTrue($connection->rollBack());
        self::assertSame(0, $connection->getTransactionLevel());
        self::assertFalse($pdo->inTransaction());
        self::assertSame([], $this->values($inner));
    }

    /** @return array{QueryBuilder, PDOConnection, PDO, PDO} */
    private function createFaultInjectingBuilder(string $fault, bool $unique = false): array
    {
        $inner = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->createTable($inner, $unique);
        $pdo = new SQLiteBatchRollbackFaultPDO($inner, $fault);
        $connection = new PDOConnection($this->settings());
        (new \ReflectionProperty(PDOConnection::class, 'connection'))->setValue($connection, $pdo);
        return [new QueryBuilder($connection), $connection, $pdo, $inner];
    }

    /** @return array{QueryBuilder, PDOConnection, PDO} */
    private function createBuilder(bool $unique): array
    {
        $connection = new PDOConnection($this->settings());
        $pdo = $connection->pdo();
        $this->createTable($pdo, $unique);
        return [new QueryBuilder($connection), $connection, $pdo];
    }

    private function createTable(PDO $pdo, bool $unique): void
    {
        $pdo->exec('CREATE TABLE batch_items (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT NOT NULL'
            . ($unique ? ' UNIQUE' : '') . ')');
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

    /** @return list<array{value: string}> */
    private function rows(int $count): array
    {
        return array_map(static fn (int $i): array => ['value' => "row-{$i}"], range(1, $count));
    }

    /** @return list<string> */
    private function values(PDO $pdo): array
    {
        return $pdo->query('SELECT value FROM batch_items ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    }
}

final class SQLiteBatchRollbackFaultPDO extends PDO
{
    private bool $failureInjected = false;

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
        $matchesFault = match ($this->fault) {
            'rollback' => str_starts_with($statement, 'ROLLBACK TO SAVEPOINT '),
            'release' => str_starts_with($statement, 'RELEASE SAVEPOINT '),
            default => false,
        };
        if ($matchesFault && !$this->failureInjected) {
            $this->failureInjected = true;
            throw new PDOException('injected ' . strtoupper($this->fault === 'rollback' ? 'ROLLBACK TO' : 'RELEASE') . ' failure');
        }
        return $this->inner->exec($statement);
    }
}