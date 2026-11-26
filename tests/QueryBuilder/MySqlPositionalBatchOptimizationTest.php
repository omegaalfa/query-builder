<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\Interfaces\DatabaseValueInterface;
use Omegaalfa\QueryBuilder\Interfaces\QueryLoggerInterface;
use Omegaalfa\QueryBuilder\QueryBuilder;
use PDO;
use PHPUnit\Framework\TestCase;

final class MySqlPositionalBatchOptimizationTest extends TestCase
{
    private PDOConnection $connection;
    private PDO $pdo;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_mysql') || getenv('DOCKER_MYSQL_HOST') === false) {
            self::markTestSkipped('Docker MySQL is not configured.');
        }

        $this->connection = new PDOConnection(new DatabaseSettings(
            'mysql',
            getenv('DOCKER_MYSQL_HOST') ?: 'mysql',
            getenv('DOCKER_MYSQL_DATABASE') ?: 'query_builder_test',
            (int) (getenv('DOCKER_MYSQL_PORT') ?: 3306),
            getenv('DOCKER_MYSQL_USERNAME') ?: 'query_builder',
            getenv('DOCKER_MYSQL_PASSWORD') ?: 'query-builder-test',
        ));
        $this->pdo = $this->connection->pdo();
        $this->pdo->exec('DROP TABLE IF EXISTS positional_batch_items');
        $this->pdo->exec('CREATE TABLE positional_batch_items (id INT PRIMARY KEY AUTO_INCREMENT, value VARCHAR(255), amount DOUBLE NULL, enabled TINYINT NULL, note VARCHAR(255) NULL)');
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->exec('DROP TABLE IF EXISTS positional_batch_items');
        }
        if (isset($this->connection)) {
            $this->connection->disconnect();
        }
    }

    public function testOneThousandRowsUsePositionalSqlAndPreserveValues(): void
    {
        $logger = new MySqlBatchLogger();
        $rows = [];
        for ($id = 1; $id <= 1000; ++$id) {
            $rows[] = [
                'value' => $id % 2 === 0 ? 'repeated' : "row-{$id}",
                'amount' => $id % 3 === 0 ? null : $id / 10,
                'enabled' => $id % 2 === 0,
                'note' => $id === 777 ? new MySqlBatchValue('special') : null,
            ];
        }

        $result = (new QueryBuilder($this->connection, logger: $logger))
            ->insertBatch('positional_batch_items', $rows)
            ->returnLastInsertId()
            ->execute();

        self::assertSame(1000, $result->count);
        // MySQL reports the first generated id for a multi-row INSERT.
        self::assertSame('1', $result->insertId);
        self::assertCount(1, $logger->queries);
        self::assertStringContainsString('?', $logger->queries[0]['sql']);
        self::assertStringNotContainsString(':param', $logger->queries[0]['sql']);
        self::assertCount(4000, $logger->queries[0]['params']);
        self::assertSame(1000, (int) $this->pdo->query('SELECT COUNT(*) FROM positional_batch_items')->fetchColumn());
        self::assertSame('special', $this->pdo->query('SELECT note FROM positional_batch_items WHERE id = 777')->fetchColumn());
    }

    public function testSingleRowAndPostgreSqlRemainNamed(): void
    {
        $single = (new QueryBuilder($this->connection))
            ->insertBatch('positional_batch_items', [['value' => 'one', 'amount' => 1.5]]);
        self::assertStringContainsString(':param0', $single->getQuerySql());

        $postgres = new class extends \Omegaalfa\QueryBuilder\QueryBuilderOperations {
            public function __construct() { $this->setDriver('pgsql'); }
        };
        self::assertStringContainsString(':param0', $postgres->insertBatch('items', [['value' => 'one'], ['value' => 'two']])->getQuerySql());
    }
}

final class MySqlBatchValue implements DatabaseValueInterface
{
    public function __construct(private readonly string $value) {}
    public function value(): mixed { return $this->value; }
    public function pdoType(): int { return PDO::PARAM_STR; }
    public function sqlType(): ?string { return null; }
    public function supportsDriver(string $driver): bool { return $driver === 'mysql'; }
    public function cacheValue(): string { return $this->value; }
}

final class MySqlBatchLogger implements QueryLoggerInterface
{
    /** @var list<array{sql:string, params:array}> */
    public array $queries = [];

    public function logQuery(string $sql, array $params, float $duration, int $rowCount): void
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params];
    }

    public function logError(string $sql, array $params, \Throwable $error): void {}
}
