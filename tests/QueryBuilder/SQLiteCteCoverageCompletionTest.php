<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\PostgreSQL\PgVector\Vector;
use Omegaalfa\QueryBuilder\QueryBuilder;
use Omegaalfa\QueryBuilder\QueryBuilderOperations;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SQLiteCteCoverageCompletionTest extends TestCase
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

    public function testRepeatedNamedRawCteBindingsRemainSeparateAndEqual(): void
    {
        $builder = (new QueryBuilder($this->connection))
            ->with(
                'source',
                'SELECT :value AS first_value, :value AS second_value',
                ['value' => 7],
            )
            ->select('source', ['first_value', 'second_value']);

        self::assertSame(
            'WITH "source" AS (SELECT :param0 AS first_value, :param1 AS second_value) '
                . 'SELECT "first_value", "second_value" FROM "source"',
            $builder->getQuerySql(),
        );
        self::assertSame(
            [':param0' => 7, ':param1' => 7],
            (new ReflectionMethod(QueryBuilderOperations::class, 'currentQueryParams'))->invoke($builder),
        );
        self::assertSame(
            [['first_value' => 7, 'second_value' => 7]],
            iterator_to_array($builder->execute()->data, false),
        );
    }

    public function testNestedClosureCtesPreserveBindingsAndExecute(): void
    {
        $builder = (new QueryBuilder($this->connection))
            ->with('outer_source', static function (QueryBuilderOperations $outer): QueryBuilderOperations {
                return $outer
                    ->with('middle_source', static function (QueryBuilderOperations $middle): QueryBuilderOperations {
                        return $middle
                            ->with('inner_source', 'SELECT id FROM items WHERE id = :inner_id', ['inner_id' => 1])
                            ->select('inner_source', ['id']);
                    })
                    ->select('middle_source', ['id']);
            })
            ->select('outer_source', ['id']);

        $sql = $builder->getQuerySql();
        self::assertSame(1, substr_count($sql, ':param0'));
        self::assertSame(
            [':param0' => 1],
            (new ReflectionMethod(QueryBuilderOperations::class, 'currentQueryParams'))->invoke($builder),
        );
        self::assertSame([['id' => 1]], iterator_to_array($builder->execute()->data, false));
    }

    public function testPostgresqlQuotedRegionsRemainUntouchedDuringCteRemapping(): void
    {
        $connection = new PDOConnection(new DatabaseSettings('pgsql', 'localhost', 'query_builder', 5432, '', ''));
        $builder = (new QueryBuilder($connection))
            ->with(
                'source',
                "SELECT :number AS number, E':number ?' AS escaped, \$tag\$:number ?\$tag\$ AS body",
                ['number' => 7],
            )
            ->select('source', ['number', 'escaped', 'body']);

        self::assertSame(
            'WITH "source" AS (SELECT :param0 AS number, E\':number ?\' AS escaped, $tag$:number ?$tag$ AS body) '
                . 'SELECT "number", "escaped", "body" FROM "source"',
            $builder->getQuerySql(),
        );
        $params = (new ReflectionMethod(QueryBuilderOperations::class, 'currentQueryParams'))->invoke($builder);
        self::assertSame([':param0' => 7], $params);
    }

    public function testDatabaseValueInterfaceTypeSurvivesCteRemapping(): void
    {
        $connection = new PDOConnection(new DatabaseSettings('pgsql', 'localhost', 'query_builder', 5432, '', ''));
        $vector = new Vector([1, 2, 3], 3);
        $builder = (new QueryBuilder($connection))
            ->with('source', 'SELECT :embedding::vector AS embedding', ['embedding' => $vector])
            ->select('source', ['embedding']);

        self::assertSame(
            'WITH "source" AS (SELECT :param0::vector AS embedding) SELECT "embedding" FROM "source"',
            $builder->getQuerySql(),
        );
        $params = (new ReflectionMethod(QueryBuilderOperations::class, 'currentQueryParams'))->invoke($builder);
        self::assertCount(1, $params);
        self::assertSame($vector, $params[':param0']);
        self::assertSame('[1,2,3]', $params[':param0']->value());
        self::assertSame('pgsql', $connection->getDriver());
    }
}
