<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use LogicException;
use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\Exceptions\QueryException;
use Omegaalfa\QueryBuilder\Exceptions\UnsupportedDatabaseFeatureException;
use Omegaalfa\QueryBuilder\Interfaces\ConnectionInterface;
use Omegaalfa\QueryBuilder\QueryBuilder;
use Omegaalfa\QueryBuilder\QueryBuilderOperations;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class SQLiteReturningIntegrationTest extends TestCase
{
    public function testInsertReturnsChangedRow(): void
    {
        [$builder] = $this->createBuilder();

        $result = $builder->insert('returning_items', ['name' => 'created', 'state' => 'new'])
            ->returning(['id'])
            ->execute();

        self::assertSame([['id' => 1]], iterator_to_array($result->data, false));
        self::assertFalse($result->insertId);
    }

    public function testUpdateReturnsUpdatedRow(): void
    {
        [$builder, $pdo] = $this->createBuilder();
        $pdo->exec("INSERT INTO returning_items (name, state) VALUES ('existing', 'new')");

        $result = $builder->update('returning_items', ['state' => 'updated'])
            ->where('id', '=', 1)
            ->returning(['id', 'state'])
            ->execute();

        self::assertSame([['id' => 1, 'state' => 'updated']], iterator_to_array($result->data, false));
    }

    public function testDeleteReturnsDeletedRow(): void
    {
        [$builder, $pdo] = $this->createBuilder();
        $pdo->exec("INSERT INTO returning_items (name, state) VALUES ('to-delete', 'old')");

        $result = $builder->delete('returning_items')
            ->where('id', '=', 1)
            ->returning(['id', 'name'])
            ->execute();

        self::assertSame([['id' => 1, 'name' => 'to-delete']], iterator_to_array($result->data, false));
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM returning_items')->fetchColumn());
    }

    public function testReturningSupportsMultipleColumns(): void
    {
        [$builder] = $this->createBuilder();

        $result = $builder->insert('returning_items', ['name' => 'multi', 'state' => 'ready'])
            ->returning(['id', 'name', 'state'])
            ->execute();

        self::assertSame(
            [['id' => 1, 'name' => 'multi', 'state' => 'ready']],
            iterator_to_array($result->data, false),
        );
    }

    public function testRejectsSQLiteVersionsBefore335(): void
    {
        $sqlite = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $sqlite->exec('CREATE TABLE returning_items (id INTEGER PRIMARY KEY, name TEXT NOT NULL, state TEXT NOT NULL)');
        $versionStatement = $this->createMock(PDOStatement::class);
        $versionStatement->expects(self::once())->method('fetchColumn')->willReturn('3.34.1');
        $pdo = new class($versionStatement) extends PDO {
            public function __construct(private readonly PDOStatement $versionStatement)
            {
            }

            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
            {
                if ($query !== 'SELECT sqlite_version()') {
                    throw new LogicException('Unexpected query while validating SQLite feature support.');
                }

                return $this->versionStatement;
            }
        };
        $connection = new class($pdo) implements ConnectionInterface {
            public function __construct(private readonly PDO $pdo)
            {
            }

            public function connect(): void {}
            public function pdo(bool $bufferedQuery = true): PDO { return $this->pdo; }
            public function disconnect(): void {}
            public function transaction(callable $callback): mixed { return $callback($this->pdo); }
            public function getDriver(): string { return 'sqlite'; }
        };

        $this->expectException(UnsupportedDatabaseFeatureException::class);
        (new QueryBuilder($connection))
            ->insert('returning_items', ['name' => 'not-inserted', 'state' => 'new'])
            ->returning(['id'])
            ->execute();
    }

    public function testRejectsReturningForUnsupportedDriver(): void
    {
        $builder = new class extends QueryBuilderOperations {
            public function __construct()
            {
                $this->setDriver('mysql');
            }
        };

        $this->expectException(UnsupportedDatabaseFeatureException::class);
        $builder->insert('returning_items', ['name' => 'not-supported'])->returning(['id']);
    }

    public function testPostgreSqlReturningSqlRemainsUnchanged(): void
    {
        $builder = new class extends QueryBuilderOperations {
            public function __construct()
            {
                $this->setDriver('pgsql');
            }
        };

        self::assertSame(
            'INSERT INTO "returning_items" ("name") VALUES (:param0) RETURNING "id", "name"',
            $builder->insert('returning_items', ['name' => 'postgres'])
                ->returning(['id', 'name'])
                ->getQuerySql(),
        );
    }

    public function testPostgreSqlReturningRemainsInsertOnly(): void
    {
        $builder = new class extends QueryBuilderOperations {
            public function __construct()
            {
                $this->setDriver('pgsql');
            }
        };

        $this->expectException(QueryException::class);
        $builder->update('returning_items', ['name' => 'postgres'])->returning(['id']);
    }

    public function testInsertWithoutReturningKeepsPreviousResultBehavior(): void
    {
        [$builder, $pdo] = $this->createBuilder();

        $result = $builder->insert('returning_items', ['name' => 'plain', 'state' => 'stored'])->execute();

        self::assertSame([], iterator_to_array($result->data, false));
        self::assertFalse($result->insertId);
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM returning_items')->fetchColumn());
    }

    public function testSQLiteReturningIsNotEnabledForBatchInsertInThisStep(): void
    {
        $builder = new class extends QueryBuilderOperations {
            public function __construct()
            {
                $this->setDriver('sqlite');
            }
        };

        $this->expectException(\Omegaalfa\QueryBuilder\Exceptions\QueryException::class);
        $builder->insertBatch('returning_items', [['name' => 'batch']])->returning(['id']);
    }

    /** @return array{QueryBuilder, PDO} */
    private function createBuilder(): array
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
        $pdo->exec('CREATE TABLE returning_items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, state TEXT NOT NULL)');

        return [new QueryBuilder($connection), $pdo];
    }
}