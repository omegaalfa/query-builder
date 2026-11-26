<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\Exceptions\DatabaseException;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class SQLiteNestedRollbackReleaseTest extends TestCase
{
    public function testRollbackNestedThenReuseConnectionAndConfirmSavepointWasReleased(): void
    {
        [$connection, $pdo] = $this->createConnection();
        $connection->beginTransaction();
        $connection->beginTransaction();
        $pdo->exec("INSERT INTO rollback_items (value) VALUES ('rolled-back')");

        self::assertTrue($connection->rollBack());
        self::assertSame(1, $connection->getTransactionLevel());
        self::assertTrue($pdo->inTransaction());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM rollback_items')->fetchColumn());
        $this->assertSavepointDoesNotExist($pdo, 'SAVEPOINT_1');

        $connection->beginTransaction();
        $pdo->exec("INSERT INTO rollback_items (value) VALUES ('committed-inner')");
        self::assertTrue($connection->commit());
        self::assertSame(1, $connection->getTransactionLevel());
        self::assertTrue($connection->commit());

        self::assertSame(['committed-inner'], $this->values($pdo));
        self::assertSame(0, $connection->getTransactionLevel());
        self::assertFalse($pdo->inTransaction());
    }

    public function testRollbackAtThirdLevelReleasesOnlyTheInnermostSavepoint(): void
    {
        [$connection, $pdo] = $this->createConnection();
        $connection->beginTransaction();
        $connection->beginTransaction();
        $connection->beginTransaction();
        $pdo->exec("INSERT INTO rollback_items (value) VALUES ('level-three')");

        self::assertTrue($connection->rollBack());
        self::assertSame(2, $connection->getTransactionLevel());
        self::assertTrue($pdo->inTransaction());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM rollback_items')->fetchColumn());
        $this->assertSavepointDoesNotExist($pdo, 'SAVEPOINT_2');

        $connection->beginTransaction();
        self::assertSame(3, $connection->getTransactionLevel());
        self::assertTrue($connection->commit());
        self::assertTrue($connection->commit());
        self::assertTrue($connection->commit());
        self::assertSame(0, $connection->getTransactionLevel());
        self::assertFalse($pdo->inTransaction());
    }

    public function testInnerRollbackCanBeFollowedByOuterCommit(): void
    {
        [$connection, $pdo] = $this->createConnection();
        $connection->beginTransaction();
        $pdo->exec("INSERT INTO rollback_items (value) VALUES ('outer-before')");
        $connection->beginTransaction();
        $pdo->exec("INSERT INTO rollback_items (value) VALUES ('inner-discarded')");

        $connection->rollBack();
        self::assertSame(1, $connection->getTransactionLevel());
        $pdo->exec("INSERT INTO rollback_items (value) VALUES ('outer-after')");
        $connection->commit();

        self::assertSame(['outer-before', 'outer-after'], $this->values($pdo));
        self::assertSame(0, $connection->getTransactionLevel());
        self::assertFalse($pdo->inTransaction());
    }

    public function testReleaseFailureKeepsDepthAndSavepointStackUntilRetrySucceeds(): void
    {
        $inner = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $inner->exec('CREATE TABLE rollback_items (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
        $pdo = new SQLiteNestedRollbackFaultPDO($inner);
        $connection = new PDOConnection(new DatabaseSettings(
            driver: 'sqlite',
            host: '',
            database: ':memory:',
            port: 0,
            username: '',
            password: '',
        ));
        (new \ReflectionProperty(PDOConnection::class, 'connection'))->setValue($connection, $pdo);

        $connection->beginTransaction();
        $connection->beginTransaction();
        $inner->exec("INSERT INTO rollback_items (value) VALUES ('rolled-back-before-release-error')");

        try {
            $connection->rollBack();
            self::fail('The injected RELEASE failure should be reported.');
        } catch (DatabaseException $exception) {
            self::assertStringContainsString('Failed to rollback transaction', $exception->getMessage());
            self::assertInstanceOf(PDOException::class, $exception->getPreviousException());
            self::assertStringContainsString('injected RELEASE failure', $exception->getPreviousException()->getMessage());
        }

        self::assertSame(2, $connection->getTransactionLevel());
        self::assertTrue($pdo->inTransaction());
        self::assertSame(0, (int) $inner->query('SELECT COUNT(*) FROM rollback_items')->fetchColumn());

        self::assertTrue($connection->rollBack());
        self::assertSame(1, $connection->getTransactionLevel());
        self::assertTrue($connection->rollBack());
        self::assertSame(0, $connection->getTransactionLevel());
        self::assertFalse($pdo->inTransaction());
        $savepoint = $pdo->savepointName;
        self::assertNotNull($savepoint);
        self::assertNotSame('SAVEPOINT_1', $savepoint);
        self::assertSame([
            "SAVEPOINT {$savepoint}",
            "ROLLBACK TO SAVEPOINT {$savepoint}",
            "RELEASE SAVEPOINT {$savepoint}",
            "ROLLBACK TO SAVEPOINT {$savepoint}",
            "RELEASE SAVEPOINT {$savepoint}",
        ], $pdo->savepointStatements);
    }

    private function assertSavepointDoesNotExist(PDO $pdo, string $name): void
    {
        try {
            $pdo->exec("ROLLBACK TO SAVEPOINT {$name}");
            self::fail("Savepoint {$name} should have been released.");
        } catch (PDOException $exception) {
            self::assertStringContainsString('no such savepoint', strtolower($exception->getMessage()));
        }
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
        $pdo->exec('CREATE TABLE rollback_items (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
        return [$connection, $pdo];
    }

    /** @return list<string> */
    private function values(PDO $pdo): array
    {
        return $pdo->query('SELECT value FROM rollback_items ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    }
}

final class SQLiteNestedRollbackFaultPDO extends PDO
{
    public bool $releaseFailureInjected = false;

    public ?string $savepointName = null;

    /** @var list<string> */
    public array $savepointStatements = [];

    public function __construct(private readonly PDO $inner)
    {
    }

    public function beginTransaction(): bool { return $this->inner->beginTransaction(); }
    public function commit(): bool { return $this->inner->commit(); }
    public function rollBack(): bool { return $this->inner->rollBack(); }
    public function inTransaction(): bool { return $this->inner->inTransaction(); }
    public function prepare(string $query, array $options = []): PDOStatement|false { return $this->inner->prepare($query, $options); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return $this->inner->query($query, ...($fetchMode === null ? [] : [$fetchMode, ...$fetchModeArgs]));
    }

    public function exec(string $statement): int|false
    {
        if (str_starts_with($statement, 'SAVEPOINT ') || str_contains($statement, 'SAVEPOINT ')) {
            $this->savepointStatements[] = $statement;
        }
        if (preg_match('/^SAVEPOINT ([A-Za-z_][A-Za-z0-9_]*)$/', $statement, $matches) === 1) {
            $this->savepointName = $matches[1];
        }
        if (
            $this->savepointName !== null
            && $statement === "RELEASE SAVEPOINT {$this->savepointName}"
            && !$this->releaseFailureInjected
        ) {
            $this->releaseFailureInjected = true;
            throw new PDOException('injected RELEASE failure');
        }
        return $this->inner->exec($statement);
    }
}
