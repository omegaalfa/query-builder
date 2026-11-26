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

final class SQLiteUpsertIntegrationTest extends TestCase
{
    public function testInsertWithoutConflictInsertsTheRow(): void
    {
        [$builder, $pdo, $connection] = $this->createVersionedBuilder(null);

        $builder->insert('upsert_items', [
            'email' => 'one@example.test',
            'username' => 'one',
            'name' => 'First',
            'status' => 'active',
        ])->execute();

        self::assertSame('First', $this->rowByEmail($pdo, 'one@example.test')['name']);
        self::assertSame(0, $connection->versionQueryCount);
    }

    public function testUniqueConflictCanUpdateTheExistingRow(): void
    {
        [$builder, $pdo] = $this->createBuilder();
        $this->insertDirect($pdo, 'unique@example.test', 'first', 'Before', 'active');

        $builder->insert('upsert_items', [
            'email' => 'unique@example.test',
            'username' => 'second',
            'name' => 'After',
            'status' => 'active',
        ])->onConflict(['email'])->doUpdate(['username', 'name'])->execute();

        self::assertSame('After', $this->rowByEmail($pdo, 'unique@example.test')['name']);
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM upsert_items')->fetchColumn());
    }

    public function testPrimaryKeyConflictCanUpdateTheExistingRow(): void
    {
        [$builder, $pdo] = $this->createBuilder();
        $pdo->exec("INSERT INTO upsert_items (id, email, username, name, status) VALUES (7, 'old@example.test', 'old', 'Before', 'pending')");

        $builder->insert('upsert_items', [
            'id' => 7,
            'email' => 'new@example.test',
            'username' => 'new',
            'name' => 'After',
            'status' => 'active',
        ])->onConflict(['id'])->doUpdate(['name'])->execute();

        $row = $pdo->query('SELECT id, email, name, status FROM upsert_items')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(7, $row['id']);
        self::assertSame('old@example.test', $row['email']);
        self::assertSame('After', $row['name']);
        self::assertSame('pending', $row['status']);
    }

    public function testDoNothingPreservesTheConflictingRow(): void
    {
        [$builder, $pdo] = $this->createBuilder();
        $this->insertDirect($pdo, 'ignore@example.test', 'first', 'Original', 'active');

        $builder->insert('upsert_items', [
            'email' => 'ignore@example.test',
            'username' => 'second',
            'name' => 'Ignored',
            'status' => 'inactive',
        ])->onConflict(['email'])->doNothing()->execute();

        self::assertSame('Original', $this->rowByEmail($pdo, 'ignore@example.test')['name']);
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM upsert_items')->fetchColumn());
    }

    public function testDoUpdateUsesExcludedValuesAndPreservesUnselectedColumns(): void
    {
        [$builder, $pdo] = $this->createBuilder();
        $this->insertDirect($pdo, 'update@example.test', 'original-user', 'Original', 'pending');

        $builder->insert('upsert_items', [
            'email' => 'update@example.test',
            'username' => 'incoming-user',
            'name' => 'Incoming',
            'status' => 'active',
        ])->onConflict(['email'])->doUpdate(['username', 'name'])->execute();

        $row = $this->rowByEmail($pdo, 'update@example.test');
        self::assertSame('incoming-user', $row['username']);
        self::assertSame('Incoming', $row['name']);
        self::assertSame('pending', $row['status']);
    }

    public function testUnmatchedUniqueConstraintFailureIsNotSwallowed(): void
    {
        [$builder, $pdo] = $this->createBuilder();
        $this->insertDirect($pdo, 'first@example.test', 'first-user', 'First', 'active');
        $this->insertDirect($pdo, 'second@example.test', 'second-user', 'Second', 'active');

        $this->expectException(QueryException::class);
        $builder->insert('upsert_items', [
            'email' => 'first@example.test',
            'username' => 'second-user',
            'name' => 'Conflict on another unique key',
            'status' => 'active',
        ])->onConflict(['email'])->doUpdate(['username', 'name'])->execute();
    }

    public function testValuesWithQuotesAndSpecialCharactersRemainBound(): void
    {
        [$builder, $pdo] = $this->createBuilder();
        $this->insertDirect($pdo, 'quoted@example.test', 'initial', 'Before', 'active');
        $incomingName = "O'Reilly ";
        $incomingName .= '雪 ☃ ' . "'); DROP TABLE upsert_items; --";

        $builder->insert('upsert_items', [
            'email' => 'quoted@example.test',
            'username' => 'incoming',
            'name' => $incomingName,
            'status' => 'active',
        ])->onConflict(['email'])->doUpdate(['name'])->execute();

        self::assertSame($incomingName, $this->rowByEmail($pdo, 'quoted@example.test')['name']);
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM upsert_items')->fetchColumn());
    }

    public function testRejectsSQLiteVersionBefore324(): void
    {
        [$builder, $pdo, $connection] = $this->createVersionedBuilder('3.23.0');

        try {
            $builder->insert('upsert_items', [
                'email' => 'old@example.test',
                'username' => 'old',
                'name' => 'Old SQLite',
                'status' => 'active',
            ])->onConflict(['email'])->doNothing()->execute();
            self::fail('SQLite versions before 3.24.0 must reject UPSERT.');
        } catch (UnsupportedDatabaseFeatureException $exception) {
            self::assertStringContainsString('3.24.0', $exception->getMessage());
        }

        self::assertSame(1, $connection->versionQueryCount);
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM upsert_items')->fetchColumn());
    }

    public function testSQLite324SupportsUpsert(): void
    {
        [$builder, $pdo, $connection] = $this->createVersionedBuilder('3.24.0');
        $this->insertDirect($pdo, 'minimum@example.test', 'minimum-user', 'Before', 'active');

        $builder->insert('upsert_items', [
            'email' => 'minimum@example.test',
            'username' => 'incoming-user',
            'name' => 'At minimum version',
            'status' => 'active',
        ])->onConflict(['email'])->doUpdate(['name'])->execute();

        self::assertSame('At minimum version', $this->rowByEmail($pdo, 'minimum@example.test')['name']);
        self::assertSame(1, $connection->versionQueryCount);
    }

    public function testSQLiteUpsertCanUseTheExistingReturningFlow(): void
    {
        [$builder, $pdo] = $this->createBuilder();
        $this->insertDirect($pdo, 'returning@example.test', 'original', 'Before', 'active');

        $result = $builder->insert('upsert_items', [
            'email' => 'returning@example.test',
            'username' => 'incoming',
            'name' => 'After',
            'status' => 'active',
        ])->onConflict(['email'])->doUpdate(['name'])->returning(['id', 'name'])->execute();

        self::assertSame([['id' => 1, 'name' => 'After']], iterator_to_array($result->data, false));
    }

    public function testSQLiteOnConflictIsNotEnabledForInsertBatch(): void
    {
        $builder = new class extends QueryBuilderOperations {
            public function __construct()
            {
                $this->setDriver('sqlite');
            }
        };

        $this->expectException(UnsupportedDatabaseFeatureException::class);
        $builder->insertBatch('upsert_items', [[
            'email' => 'batch@example.test',
            'username' => 'batch',
            'name' => 'Batch',
            'status' => 'active',
        ]])->onConflict(['email']);
    }

    public function testGeneratesSQLiteDoNothingSyntax(): void
    {
        $builder = $this->sqliteOperations();

        $sql = $builder->insert('upsert_items', ['email' => 'a@example.test', 'name' => 'A'])
            ->onConflict(['email'])
            ->doNothing()
            ->getQuerySql();

        self::assertSame(
            'INSERT INTO "upsert_items" ("email", "name") VALUES (:param0, :param1) '
            . 'ON CONFLICT ("email") DO NOTHING',
            $sql,
        );
    }

    public function testGeneratesSQLiteDoUpdateWithExcludedValues(): void
    {
        $builder = $this->sqliteOperations();

        $sql = $builder->insert('upsert_items', ['email' => 'a@example.test', 'name' => 'A'])
            ->onConflict(['email'])
            ->doUpdate(['name'])
            ->getQuerySql();

        self::assertSame(
            'INSERT INTO "upsert_items" ("email", "name") VALUES (:param0, :param1) '
            . 'ON CONFLICT ("email") DO UPDATE SET "name" = EXCLUDED."name"',
            $sql,
        );
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
        $pdo->exec('CREATE TABLE upsert_items (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE, username TEXT NOT NULL UNIQUE, name TEXT NOT NULL, status TEXT NOT NULL)');

        return [new QueryBuilder($connection), $pdo];
    }

    /** @return array{QueryBuilder, PDO, PDO} */
    private function createVersionedBuilder(?string $version): array
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE upsert_items (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE, username TEXT NOT NULL UNIQUE, name TEXT NOT NULL, status TEXT NOT NULL)');
        $versionStatement = null;
        if ($version !== null) {
            $versionStatement = $this->createStub(PDOStatement::class);
            $versionStatement->method('fetchColumn')->willReturn($version);
        }
        $versionedPdo = new class($pdo, $versionStatement) extends PDO {
            public int $versionQueryCount = 0;

            public function __construct(
                private readonly PDO $inner,
                private readonly ?PDOStatement $versionStatement,
            ) {
            }

            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
            {
                if ($query === 'SELECT sqlite_version()') {
                    ++$this->versionQueryCount;
                    if ($this->versionStatement === null) {
                        throw new LogicException('SQLite version must not be queried for this operation.');
                    }

                    return $this->versionStatement;
                }

                throw new LogicException('Unexpected direct query through the versioned PDO connection.');
            }

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                return $this->inner->prepare($query, $options);
            }
        };
        $connection = new class($versionedPdo) implements ConnectionInterface {
            public function __construct(private readonly PDO $pdo)
            {
            }

            public function connect(): void {}
            public function pdo(bool $bufferedQuery = true): PDO { return $this->pdo; }
            public function disconnect(): void {}
            public function transaction(callable $callback): mixed { return $callback($this->pdo); }
            public function getDriver(): string { return 'sqlite'; }
        };

        return [new QueryBuilder($connection), $pdo, $versionedPdo];
    }

    private function insertDirect(PDO $pdo, string $email, string $username, string $name, string $status): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO upsert_items (email, username, name, status) VALUES (:email, :username, :name, :status)'
        );
        $statement->execute([
            'email' => $email,
            'username' => $username,
            'name' => $name,
            'status' => $status,
        ]);
    }

    /** @return array<string, mixed> */
    private function rowByEmail(PDO $pdo, string $email): array
    {
        $statement = $pdo->prepare('SELECT * FROM upsert_items WHERE email = :email');
        $statement->execute(['email' => $email]);

        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    private function sqliteOperations(): QueryBuilderOperations
    {
        return new class extends QueryBuilderOperations {
            public function __construct()
            {
                $this->setDriver('sqlite');
            }
        };
    }
}
