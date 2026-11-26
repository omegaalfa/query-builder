<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\Exceptions\QueryException;
use Omegaalfa\QueryBuilder\Interfaces\ConnectionInterface;
use Omegaalfa\QueryBuilder\QueryBuilder;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class SQLiteBatchChunkExecutionTest extends TestCase
{
    public function testSmallBatchUsesSingleStatementAndReportsCount(): void
    {
        [$builder, $pdo] = $this->builder();
        $result = $builder->insertBatch('items', [['value' => 'a'], ['value' => 'b']])->execute();

        self::assertSame(2, $result->count);
        self::assertSame(['a', 'b'], $this->values($pdo));
    }

    public function testReducedLimitSplitsBatchIntoTwoChunksInOrder(): void
    {
        [$inner, $connection] = $this->limitedConnection(3);
        $builder = new QueryBuilder($connection);
        $rows = array_map(static fn (int $i): array => ['value' => "value-{$i}"], range(1, 4));

        $result = $builder->insertBatch('items', $rows)->execute();

        self::assertSame(4, $result->count);
        self::assertSame(['value-1', 'value-2', 'value-3', 'value-4'], $this->values($inner));
    }

    public function testReducedLimitSplitsBatchIntoThreeOrMoreChunks(): void
    {
        [$inner, $connection] = $this->limitedConnection(3);
        $builder = new QueryBuilder($connection);
        $rows = array_map(static fn (int $i): array => ['value' => "value-{$i}"], range(1, 7));

        $result = $builder->insertBatch('items', $rows)->execute();

        self::assertSame(7, $result->count);
        self::assertSame(array_column($rows, 'value'), $this->values($inner));
    }

    public function testChunksRespectMultipleColumnsAndSpecialCharacters(): void
    {
        [$inner, $connection] = $this->limitedConnection(4);
        $builder = new QueryBuilder($connection);
        $rows = [
            ['value' => "O'Reilly", 'note' => 'a; b'],
            ['value' => 'ç漢字', 'note' => 'line\nbreak'],
            ['value' => 'third', 'note' => 'fourth'],
        ];

        $result = $builder->insertBatch('items', $rows)->execute();
        $stored = $inner->query('SELECT value, note FROM items ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

        self::assertSame(3, $result->count);
        self::assertSame($rows, $stored);
    }

    public function testLastInsertIdIsFromFinalChunk(): void
    {
        [$inner, $connection] = $this->limitedConnection(2);
        $builder = new QueryBuilder($connection);

        $result = $builder->insertBatch('items', array_map(
            static fn (int $i): array => ['value' => "row-{$i}"],
            range(1, 5),
        ))->returnLastInsertId()->execute();

        self::assertSame(5, $result->count);
        self::assertSame('5', $result->insertId);
        self::assertSame('5', $builder->getInsertId());
        self::assertSame('5', $inner->lastInsertId());
    }

    public function testFailureInLaterChunkRollsBackEntireBatchAndPreservesBuilderState(): void
    {
        [$builder, $pdo, $connection] = $this->builder(true);
        $rows = array_map(static fn (int $i): array => ['value' => "value-{$i}"], range(1, 900));
        $rows[] = ['value' => 'value-1'];
        $builder->insertBatch('items', $rows);
        $sql = $builder->getQuerySql();

        try {
            $builder->execute();
            self::fail('The duplicate in the second chunk should fail.');
        } catch (QueryException $exception) {
            self::assertInstanceOf(PDOException::class, $exception->getPrevious());
        }

        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM items')->fetchColumn());
        self::assertSame($sql, $builder->getQuerySql());
        self::assertSame(0, $connection->getTransactionLevel());
        self::assertFalse($pdo->inTransaction());
    }

    public function testFailedBatchInsideExternalTransactionPreservesEarlierAndLaterWrites(): void
    {
        [$builder, $pdo, $connection] = $this->builder(true);
        $connection->beginTransaction();
        $pdo->exec("INSERT INTO items (value) VALUES ('keep')");
        $rows = array_map(static fn (int $i): array => ['value' => "value-{$i}"], range(1, 900));
        $rows[] = ['value' => 'keep'];

        try {
            $builder->insertBatch('items', $rows)->execute();
            self::fail('The duplicate in the later chunk should fail.');
        } catch (QueryException) {
            self::assertTrue($pdo->inTransaction());
            self::assertSame(1, $connection->getTransactionLevel());
        }

        $pdo->exec("INSERT INTO items (value) VALUES ('after')");
        $connection->commit();
        self::assertSame(['keep', 'after'], $this->values($pdo));
        self::assertSame(0, $connection->getTransactionLevel());
    }

    public function testSavepointPreservesUntrackedPdoTransactionState(): void
    {
        [$builder, $pdo, $connection] = $this->builder();
        $pdo->beginTransaction();
        $rows = array_map(static fn (int $i): array => ['value' => "row-{$i}"], range(1, 901));

        $result = $builder->insertBatch('items', $rows)->execute();

        self::assertSame(901, $result->count);
        self::assertTrue($pdo->inTransaction());
        self::assertSame(0, $connection->getTransactionLevel());
        $pdo->rollBack();
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM items')->fetchColumn());
    }

    public function testInspectionSqlAndDebugBindingsRemainAvailable(): void
    {
        [$builder] = $this->builder();
        $builder->insertBatch('items', [['value' => "O'Reilly"], ['value' => 'other']]);

        self::assertSame(
            'INSERT INTO "items" ("value") VALUES (:param0), (:param1)',
            $builder->getQuerySql(),
        );
        self::assertStringContainsString("'O\\'Reilly'", $builder->toSql(withParams: true));
        self::assertIsArray($builder->explain());
    }

    /** @return array{QueryBuilder, PDO, PDOConnection} */
    private function builder(bool $unique = false): array
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
        $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT NOT NULL'
            . ($unique ? ' UNIQUE' : '') . ', note TEXT)');
        return [new QueryBuilder($connection), $pdo, $connection];
    }

    /** @return array{PDO, ConnectionInterface} */
    private function limitedConnection(int $maxVariables): array
    {
        $inner = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $inner->exec('CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT NOT NULL, note TEXT)');
        $pdo = new class($inner, $maxVariables) extends PDO {
            public function __construct(private readonly PDO $inner, private readonly int $maxVariables) {}
            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                preg_match_all('/:param\d+/', $query, $matches);
                if (count($matches[0]) > $this->maxVariables) {
                    throw new PDOException('SQLSTATE[HY000]: General error: 1 too many SQL variables');
                }
                return $this->inner->prepare($query, $options);
            }
            public function lastInsertId(?string $name = null): string|false { return $this->inner->lastInsertId($name); }
        };
        $connection = new class($pdo) implements ConnectionInterface {
            public function __construct(private readonly PDO $pdo) {}
            public function connect(): void {}
            public function pdo(bool $bufferedQuery = true): PDO { return $this->pdo; }
            public function disconnect(): void {}
            public function transaction(callable $callback): mixed { return $callback($this->pdo); }
            public function getDriver(): string { return 'sqlite'; }
        };
        return [$inner, $connection];
    }

    /** @return list<string> */
    private function values(PDO $pdo): array
    {
        return $pdo->query('SELECT value FROM items ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    }
}