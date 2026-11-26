<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\QueryBuilder;
use Omegaalfa\QueryBuilder\QueryBuilderOperations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SQLiteCtePlaceholderRemappingOptimizationTest extends TestCase
{
    private PDOConnection $connection;

    private \PDO $pdo;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }

        $this->connection = new PDOConnection(new DatabaseSettings('sqlite', '', ':memory:', 0, '', ''));
        $this->pdo = $this->connection->pdo();
        $this->pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, value TEXT)');
        $this->pdo->exec("INSERT INTO items VALUES (1, 'one'), (2, 'two')");
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->disconnect();
        }
    }

    public function testSimpleQueriesKeepTheirExistingSqlAndBindings(): void
    {
        $builder = new QueryBuilder($this->connection);
        $builder->select('items', ['value'])->where('id', '=', 1);

        self::assertSame('SELECT "value" FROM "items" WHERE "id" = :param0', $builder->getQuerySql());
        self::assertSame(
            [':param0' => 1],
            (new ReflectionMethod(QueryBuilderOperations::class, 'currentQueryParams'))->invoke($builder),
        );
        self::assertSame([['value' => 'one']], iterator_to_array($builder->execute()->data, false));
    }

    #[DataProvider('cteDepthProvider')]
    public function testChainedCtesPreserveBindingOrderAndExecution(int $depth): void
    {
        $builder = new QueryBuilder($this->connection);
        for ($index = 0; $index < $depth; ++$index) {
            $source = $index === 0 ? 'items' : 'cte_' . ($index - 1);
            $builder->with(
                'cte_' . $index,
                'SELECT id, value FROM ' . $source . ' WHERE id = :id' . $index,
                ['id' . $index => 1],
            );
        }
        $builder->select('cte_' . ($depth - 1), ['value']);
        $sql = $builder->getQuerySql();

        $expectedBindings = [];
        for ($index = 0; $index < $depth; ++$index) {
            $expectedBindings[':param' . $index] = 1;
        }

        self::assertStringContainsString('WITH ', $sql);
        self::assertSame($expectedBindings, (new ReflectionMethod(QueryBuilderOperations::class, 'currentQueryParams'))->invoke($builder));
        self::assertSame([['value' => 'one']], iterator_to_array($builder->execute()->data, false));
    }

    public static function cteDepthProvider(): iterable
    {
        yield 'one CTE' => [1];
        yield 'four CTEs' => [4];
        yield 'eight CTEs' => [8];
    }

    public function testRepeatedMarkersAndSpecialRegionsKeepTypesAndValues(): void
    {
        $builder = (new QueryBuilder($this->connection))
            ->with(
                'source',
                "SELECT :first AS first_value, :second AS second_value, ':first' AS literal /* :second */",
                [
                    'first' => 7,
                    'second' => false,
                ],
            )
            ->select('source', ['first_value', 'second_value']);

        $sql = $builder->getQuerySql();
        self::assertStringContainsString("':first'", $sql);
        self::assertStringContainsString('/* :second */', $sql);
        self::assertSame(
            [':param0' => 7, ':param1' => false],
            (new ReflectionMethod(QueryBuilderOperations::class, 'currentQueryParams'))->invoke($builder),
        );
        self::assertSame([['first_value' => 7, 'second_value' => 0]], iterator_to_array($builder->execute()->data, false));
    }

    public function testCteRemappingUsesOnePassForAnyNumberOfCteParameters(): void
    {
        $builder = new CteRemappingProbe();
        $builder->with(
            'source',
            'SELECT :first AS first_value, :second AS second_value, :third AS third_value',
            ['first' => 1, 'second' => 2, 'third' => 3],
        )->select('source', ['first_value']);

        self::assertSame(2, $builder->replaceCalls);
        self::assertSame(
            'WITH "source" AS (SELECT :param0 AS first_value, :param1 AS second_value, :param2 AS third_value) '
                . 'SELECT "first_value" FROM "source"',
            $builder->getQuerySql(),
        );
        self::assertSame(3, $builder->replaceCalls);
    }
}

final class CteRemappingProbe extends QueryBuilderOperations
{
    public int $replaceCalls = 0;

    public function __construct()
    {
        $this->driver = 'sqlite';
    }

    protected function replaceSqlPlaceholders(string $sql, callable $replacement): string
    {
        ++$this->replaceCalls;
        return parent::replaceSqlPlaceholders($sql, $replacement);
    }
}
