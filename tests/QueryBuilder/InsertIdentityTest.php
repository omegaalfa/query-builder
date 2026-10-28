<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\Interfaces\ConnectionInterface;
use Omegaalfa\QueryBuilder\QueryBuilder;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

#[AllowMockObjectsWithoutExpectations]
final class InsertIdentityTest extends TestCase
{
    public function testPlainInsertDoesNotCallLastInsertId(): void
    {
        [$builder, $pdo] = $this->mysqlBuilder();

        $result = $builder->insert('natural_keys', ['code' => 'alpha'])->execute();

        self::assertSame(0, $pdo->lastInsertIdCalls);
        self::assertFalse($result->insertId);
        self::assertFalse($builder->getInsertId());
    }

    public function testMySqlAutoIncrementIsRequestedExplicitly(): void
    {
        [$builder, $pdo] = $this->mysqlBuilder();

        $result = $builder->insert('items', ['name' => 'alpha'])
            ->returnLastInsertId()
            ->execute();

        self::assertSame(1, $pdo->lastInsertIdCalls);
        self::assertSame('73', $result->insertId);
        self::assertSame('73', $builder->getInsertId());

        $plain = $builder->insert('items', ['name' => 'beta'])->execute();
        self::assertFalse($plain->insertId);
        self::assertFalse($builder->getInsertId());
        self::assertSame(1, $pdo->lastInsertIdCalls);
    }

    public function testSqliteSupportsNaturalUuidCompositeAndExplicitAutoincrement(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not installed in this PHP runtime.');
        }
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $builder = new QueryBuilder($this->connection($pdo, 'sqlite'));
        $pdo->exec('CREATE TABLE natural_key (code TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE uuid_key (id TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE composite_key (tenant TEXT, code TEXT, PRIMARY KEY (tenant, code))');
        $pdo->exec('CREATE TABLE generated_key (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT NOT NULL)');

        self::assertFalse($builder->insert('natural_key', ['code' => 'n1', 'value' => 'ok'])->execute()->insertId);
        self::assertFalse($builder->insert('uuid_key', ['id' => '018f47ac-10ab-7c1d-a111-111111111111', 'value' => 'ok'])->execute()->insertId);
        self::assertFalse($builder->insert('composite_key', ['tenant' => 't1', 'code' => 'c1'])->execute()->insertId);

        $result = $builder->insert('generated_key', ['value' => 'ok'])->returnLastInsertId()->execute();

        self::assertSame('1', $result->insertId);
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM generated_key')->fetchColumn());
    }

    public function testSqliteAutoincrementUsesLastInsertIdOnlyWhenRequested(): void
    {
        [$builder, $pdo] = $this->builderWithTrackedLastInsertId('sqlite');

        self::assertFalse($builder->insert('items', ['name' => 'plain'])->execute()->insertId);
        self::assertSame(0, $pdo->lastInsertIdCalls);

        $result = $builder->insert('items', ['name' => 'generated'])->returnLastInsertId()->execute();
        self::assertSame('73', $result->insertId);
        self::assertSame(1, $pdo->lastInsertIdCalls);
    }

    /** @return array{QueryBuilder, PDO&object{lastInsertIdCalls:int}} */
    private function mysqlBuilder(): array
    {
        return $this->builderWithTrackedLastInsertId('mysql');
    }

    /** @return array{QueryBuilder, PDO&object{lastInsertIdCalls:int}} */
    private function builderWithTrackedLastInsertId(string $driver): array
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetch')->willReturn(false);
        $statement->method('rowCount')->willReturn(1);
        $statement->method('closeCursor')->willReturn(true);

        $pdo = new class($statement) extends PDO {
            public int $lastInsertIdCalls = 0;

            public function __construct(private readonly PDOStatement $statement) {}

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                return $this->statement;
            }

            public function lastInsertId($name = null): string|false
            {
                ++$this->lastInsertIdCalls;
                return '73';
            }
        };

        return [new QueryBuilder($this->connection($pdo, $driver)), $pdo];
    }

    private function connection(PDO $pdo, string $driver): ConnectionInterface
    {
        return new class($pdo, $driver) implements ConnectionInterface {
            public function __construct(private readonly PDO $pdo, private readonly string $driver) {}
            public function connect(): void {}
            public function disconnect(): void {}
            public function pdo(bool $bufferedQuery = true): PDO { return $this->pdo; }
            public function transaction(callable $callback): mixed { return $callback($this->pdo); }
            public function getDriver(): string { return $this->driver; }
        };
    }
}
