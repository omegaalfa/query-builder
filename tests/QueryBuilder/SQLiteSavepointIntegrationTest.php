<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\Exceptions\DatabaseException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SQLiteSavepointIntegrationTest extends TestCase
{
    public function testNestedCommitIsCommittedWithTheOuterTransaction(): void
    {
        [$connection, $pdo] = $this->createConnection();

        $connection->transaction(function (PDO $pdo) use ($connection): void {
            $this->insert($pdo, 'outer-before');
            $connection->transaction(function (PDO $pdo): void {
                $this->insert($pdo, 'inner');
            });
            $this->insert($pdo, 'outer-after');
        });

        self::assertSame(['outer-before', 'inner', 'outer-after'], $this->values($pdo));
        self::assertSame(0, $connection->getTransactionLevel());
        self::assertFalse($connection->inTransaction());
        self::assertFalse($pdo->inTransaction());
    }

    public function testInnerRollbackPreservesOuterTransaction(): void
    {
        [$connection, $pdo] = $this->createConnection();

        $connection->transaction(function (PDO $pdo) use ($connection): void {
            $this->insert($pdo, 'outer-before');

            try {
                $connection->transaction(function (PDO $pdo): void {
                    $this->insert($pdo, 'inner-rolled-back');
                    throw new RuntimeException('rollback inner transaction');
                });
            } catch (DatabaseException) {
                self::assertSame(1, $connection->getTransactionLevel());
                self::assertTrue($pdo->inTransaction());
            }

            $this->insert($pdo, 'outer-after');
        });

        self::assertSame(['outer-before', 'outer-after'], $this->values($pdo));
        self::assertSame(0, $connection->getTransactionLevel());
        self::assertFalse($pdo->inTransaction());
    }

    public function testThreeNestedTransactionLevelsCanCommit(): void
    {
        [$connection, $pdo] = $this->createConnection();

        $connection->transaction(function (PDO $pdo) use ($connection): void {
            $this->insert($pdo, 'level-one');
            $connection->transaction(function (PDO $pdo) use ($connection): void {
                $this->insert($pdo, 'level-two');
                $connection->transaction(function (PDO $pdo): void {
                    $this->insert($pdo, 'level-three');
                });
            });
        });

        self::assertSame(['level-one', 'level-two', 'level-three'], $this->values($pdo));
        self::assertSame(0, $connection->getTransactionLevel());
        self::assertFalse($connection->inTransaction());
        self::assertFalse($pdo->inTransaction());
    }

    public function testOuterRollbackUndoesChangesAfterInnerSavepointWasReleased(): void
    {
        [$connection, $pdo] = $this->createConnection();

        try {
            $connection->transaction(function (PDO $pdo) use ($connection): void {
                $this->insert($pdo, 'outer');
                $connection->transaction(function (PDO $pdo): void {
                    $this->insert($pdo, 'inner-committed');
                });
                throw new RuntimeException('rollback outer transaction');
            });
            self::fail('The outer transaction should throw.');
        } catch (DatabaseException $exception) {
            self::assertInstanceOf(RuntimeException::class, $exception->getPreviousException());
        }

        self::assertSame([], $this->values($pdo));
        self::assertSame(0, $connection->getTransactionLevel());
        self::assertFalse($connection->inTransaction());
        self::assertFalse($pdo->inTransaction());
    }

    public function testConnectionStateIsConsistentAcrossNestedCommitAndRollback(): void
    {
        [$connection, $pdo] = $this->createConnection();

        self::assertSame(0, $connection->getTransactionLevel());
        self::assertFalse($connection->inTransaction());
        self::assertFalse($pdo->inTransaction());

        self::assertTrue($connection->beginTransaction());
        self::assertSame(1, $connection->getTransactionLevel());
        self::assertTrue($connection->inTransaction());
        self::assertTrue($pdo->inTransaction());

        self::assertTrue($connection->beginTransaction());
        self::assertSame(2, $connection->getTransactionLevel());
        self::assertTrue($connection->commit());
        self::assertSame(1, $connection->getTransactionLevel());
        self::assertTrue($pdo->inTransaction());

        self::assertTrue($connection->rollBack());
        self::assertSame(0, $connection->getTransactionLevel());
        self::assertFalse($connection->inTransaction());
        self::assertFalse($pdo->inTransaction());
    }

    /** @return array{PDOConnection, PDO} */
    private function createConnection(): array
    {
        $connection = new PDOConnection(new DatabaseSettings(
            driver: 'sqlite',
            host: '',
            database: ':memory:',
            port: 0,
            username: '',
            password: '',
        ));
        $pdo = $connection->pdo();
        $pdo->exec('CREATE TABLE savepoint_items (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');

        return [$connection, $pdo];
    }

    private function insert(PDO $pdo, string $value): void
    {
        $statement = $pdo->prepare('INSERT INTO savepoint_items (value) VALUES (:value)');
        $statement->execute(['value' => $value]);
    }

    /** @return list<string> */
    private function values(PDO $pdo): array
    {
        return $pdo->query('SELECT value FROM savepoint_items ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    }
}