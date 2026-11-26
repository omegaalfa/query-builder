<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\Exceptions\QueryException;
use Omegaalfa\QueryBuilder\QueryBuilder;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class SQLiteSavepointNameIsolationTest extends TestCase
{
    public function testIndependentConnectionsReceiveDifferentInternalSavepointNames(): void
    {
        [$first, $firstPdo] = $this->connectionWithProbe();
        [$second, $secondPdo] = $this->connectionWithProbe();
        $first->beginTransaction();
        $second->beginTransaction();
        $first->beginTransaction();
        $second->beginTransaction();

        $firstName = $firstPdo->createdNames[0];
        $secondName = $secondPdo->createdNames[0];
        self::assertNotSame($firstName, $secondName);
        self::assertMatchesRegularExpression('/^SAVEPOINT_[a-f0-9]{32}_1$/', $firstName);
        self::assertMatchesRegularExpression('/^SAVEPOINT_[a-f0-9]{32}_1$/', $secondName);
        self::assertFalse(str_starts_with($firstName, 'qb_batch_'));
        self::assertFalse(str_starts_with($secondName, 'qb_batch_'));

        $first->rollBack();
        $second->rollBack();
        $first->rollBack();
        $second->rollBack();
    }

    public function testConcurrentNestedLevelsUseDistinctNamesAndRollbackCommitCorrectly(): void
    {
        [$connection, $pdo] = $this->connectionWithProbe();
        $inner = $pdo->innerPdo();
        $inner->exec('CREATE TABLE names_items (value TEXT NOT NULL)');
        $connection->beginTransaction();
        $connection->beginTransaction();
        $connection->beginTransaction();
        $inner->exec("INSERT INTO names_items VALUES ('discarded')");

        self::assertCount(2, $pdo->createdNames);
        self::assertNotSame($pdo->createdNames[0], $pdo->createdNames[1]);
        self::assertMatchesRegularExpression('/^SAVEPOINT_[a-f0-9]{32}_1$/', $pdo->createdNames[0]);
        self::assertMatchesRegularExpression('/^SAVEPOINT_[a-f0-9]{32}_2$/', $pdo->createdNames[1]);
        $connection->rollBack();
        self::assertSame(2, $connection->getTransactionLevel());
        $connection->rollBack();
        self::assertSame(1, $connection->getTransactionLevel());
        self::assertSame(0, (int) $inner->query('SELECT COUNT(*) FROM names_items')->fetchColumn());
        $connection->commit();

        self::assertSame(0, $connection->getTransactionLevel());
        self::assertFalse($inner->inTransaction());
    }

    public function testConsumerSavepointNamedSavepoint1DoesNotCollide(): void
    {
        [$connection, $pdo] = $this->connectionWithProbe();
        $inner = $pdo->innerPdo();
        $inner->exec('CREATE TABLE names_items (value TEXT NOT NULL)');
        $connection->beginTransaction();
        $inner->exec('SAVEPOINT SAVEPOINT_1');
        $inner->exec("INSERT INTO names_items VALUES ('consumer-change')");
        $connection->beginTransaction();

        $generatedName = $pdo->createdNames[0];
        self::assertNotSame('SAVEPOINT_1', $generatedName);
        self::assertMatchesRegularExpression('/^SAVEPOINT_[a-f0-9]{32}_1$/', $generatedName);
        $connection->rollBack();
        self::assertSame(1, $connection->getTransactionLevel());
        $inner->exec('ROLLBACK TO SAVEPOINT SAVEPOINT_1');
        self::assertSame(0, (int) $inner->query('SELECT COUNT(*) FROM names_items')->fetchColumn());
        $connection->commit();
        self::assertFalse($inner->inTransaction());
    }

    public function testBatchFailureInsideOuterTransactionKeepsPriorDataAndAllowsReuse(): void
    {
        $connection = new PDOConnection($this->settings());
        $pdo = $connection->pdo();
        $pdo->exec('CREATE TABLE batch_items (id INTEGER PRIMARY KEY, value TEXT UNIQUE NOT NULL)');
        $builder = new QueryBuilder($connection);
        $connection->beginTransaction();
        $pdo->exec("INSERT INTO batch_items VALUES (1, 'outer-before')");
        $rows = array_map(static fn (int $i): array => ['value' => "value-{$i}"], range(1, 900));
        $rows[] = ['value' => 'value-1'];

        try {
            $builder->insertBatch('batch_items', $rows)->execute();
            self::fail('The batch should fail in its second chunk.');
        } catch (QueryException) {
            self::assertSame(1, $connection->getTransactionLevel());
            self::assertTrue($pdo->inTransaction());
        }

        $result = $builder->insertBatch('batch_items', [['value' => 'valid-after-rollback']])->execute();
        self::assertSame(1, $result->count);
        self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM batch_items')->fetchColumn());
        self::assertSame(1, $connection->getTransactionLevel());
        $connection->rollBack();
        self::assertSame(0, $connection->getTransactionLevel());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM batch_items')->fetchColumn());
    }

    /** @return array{PDOConnection, SQLiteSavepointNameProbePDO} */
    private function connectionWithProbe(): array
    {
        $inner = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo = new SQLiteSavepointNameProbePDO($inner);
        $connection = new PDOConnection($this->settings());
        (new \ReflectionProperty(PDOConnection::class, 'connection'))->setValue($connection, $pdo);
        return [$connection, $pdo];
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
}

final class SQLiteSavepointNameProbePDO extends PDO
{
    /** @var list<string> */
    public array $createdNames = [];

    public function __construct(private readonly PDO $inner)
    {
    }

    public function innerPdo(): PDO { return $this->inner; }
    public function beginTransaction(): bool { return $this->inner->beginTransaction(); }
    public function commit(): bool { return $this->inner->commit(); }
    public function rollBack(): bool { return $this->inner->rollBack(); }
    public function inTransaction(): bool { return $this->inner->inTransaction(); }
    public function prepare(string $query, array $options = []): PDOStatement|false { return $this->inner->prepare($query, $options); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return $this->inner->query($query, ...($fetchMode === null ? [] : [$fetchMode, ...$fetchModeArgs]));
    }
    public function lastInsertId(?string $name = null): string|false { return $this->inner->lastInsertId($name); }

    public function exec(string $statement): int|false
    {
        if (preg_match('/^SAVEPOINT ([A-Za-z_][A-Za-z0-9_]*)$/', $statement, $matches) === 1) {
            $this->createdNames[] = $matches[1];
        }
        return $this->inner->exec($statement);
    }
}