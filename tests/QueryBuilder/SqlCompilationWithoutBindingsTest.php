<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\PostgreSQL\PgVector\Vector;
use Omegaalfa\QueryBuilder\QueryBuilder;
use Omegaalfa\QueryBuilder\QueryBuilderOperations;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SqlCompilationWithoutBindingsTest extends TestCase
{
    public function testSelectWithoutBindingsSkipsPlaceholderScanner(): void
    {
        $builder = new CountingQueryBuilder();

        self::assertSame(
            'SELECT `id`, `name` FROM `items` ORDER BY `id` ASC LIMIT 0 , 10',
            $builder->select('items', ['id', 'name'])->orderBy('id')->limit(10)->getQuerySql(),
        );
        self::assertSame(0, $builder->replacementCalls);
        self::assertSame([], $this->compiledParams($builder));
    }

    public function testInternalBindingsUseTheCompiledFastPath(): void
    {
        $builder = new CountingQueryBuilder();

        self::assertSame(
            'SELECT * FROM `items` WHERE `id` = :param0 AND `name` = :param1',
            $builder->select('items')->where('id', '=', 1)->where('name', '=', 'one')->getQuerySql(),
        );
        self::assertSame(0, $builder->replacementCalls);
        self::assertSame([':param0' => 1, ':param1' => 'one'], $this->compiledParams($builder));
    }

    public function testRawSqlAndLiteralMarkersRemainUnchangedWithoutBindings(): void
    {
        $builder = new CountingQueryBuilder();
        $sql = "SELECT '?' AS marker /* :param0 */ -- :param1\nFROM items";

        self::assertSame($sql, $builder->raw($sql)->getQuerySql());
        self::assertSame(0, $builder->replacementCalls);
        self::assertSame([], $this->compiledParams($builder));
    }

    public function testCteWithoutBindingsSkipsPlaceholderScanner(): void
    {
        $builder = new CountingQueryBuilder();
        (new ReflectionClass($builder))->getProperty('driver')->setValue($builder, 'sqlite');

        self::assertSame(
            'WITH "source" AS (SELECT id, \'?\' AS marker /* :param0 */) SELECT "id" FROM "source"',
            $builder->with('source', "SELECT id, '?' AS marker /* :param0 */")
                ->select('source', ['id'])
                ->getQuerySql(),
        );
        self::assertSame(0, $builder->replacementCalls);
    }

    public function testCteBindingsPreserveTheExistingRemappingPath(): void
    {
        $builder = new CountingQueryBuilder();

        $sql = $builder->with('source', 'SELECT id FROM items WHERE id = ?', [1])
            ->select('source', ['id'])
            ->where('id', '=', 1)
            ->getQuerySql();

        self::assertSame(
            'WITH `source` AS (SELECT id FROM items WHERE id = :param0) SELECT `id` FROM `source` WHERE `id` = :param1',
            $sql,
        );
        self::assertGreaterThanOrEqual(2, $builder->replacementCalls);
        self::assertSame([':param0' => 1, ':param1' => 1], $this->compiledParams($builder));
    }

    public function testFastPathKeepsDialectSpecificSqlForSupportedDrivers(): void
    {
        foreach (['mysql' => '`', 'pgsql' => '"', 'sqlite' => '"'] as $driver => $quote) {
            $builder = new CountingQueryBuilder();
            (new ReflectionClass($builder))->getProperty('driver')->setValue($builder, $driver);

            self::assertSame(
                sprintf('SELECT %1$sid%1$s FROM %1$sitems%1$s', $quote),
                $builder->select('items', ['id'])->getQuerySql(),
                $driver,
            );
            self::assertSame(0, $builder->replacementCalls, $driver);
        }
    }

    public function testNamedBindingsPreserveNamesOrderAndValues(): void
    {
        $builder = new CountingQueryBuilder();

        self::assertSame(
            'SELECT * FROM items WHERE tenant_id = :param0 AND status = :param1 AND kind = :param2',
            $builder->raw(
                'SELECT * FROM items WHERE tenant_id = :tenant AND status = :status AND kind = :kind',
                ['tenant' => 7, 'status' => 'active', 'kind' => 'primary'],
            )->getQuerySql(),
        );
        self::assertSame(0, $builder->replacementCalls);
        self::assertSame(
            [':param0' => 7, ':param1' => 'active', ':param2' => 'primary'],
            $this->compiledParams($builder),
        );
    }

    public function testCteNamedBindingsKeepScannerAndValues(): void
    {
        $builder = new CountingQueryBuilder();

        self::assertSame(
            'WITH `source` AS (SELECT id FROM items WHERE tenant_id = :param0 AND status = :param1) SELECT `id` FROM `source`',
            $builder->with(
                'source',
                'SELECT id FROM items WHERE tenant_id = :tenant AND status = :status',
                ['tenant' => 7, 'status' => 'active'],
            )->select('source', ['id'])->getQuerySql(),
        );
        self::assertGreaterThanOrEqual(2, $builder->replacementCalls);
        self::assertSame([':param0' => 7, ':param1' => 'active'], $this->compiledParams($builder));
    }

    public function testNamedBindingsPreserveSupportedScalarTypes(): void
    {
        $builder = new CountingQueryBuilder();

        $builder->raw(
            'SELECT :enabled AS enabled, :missing AS missing, :count AS count, :label AS label',
            ['enabled' => true, 'missing' => null, 'count' => 42, 'label' => 'primary'],
        )->getQuerySql();

        self::assertSame(
            [':param0' => true, ':param1' => null, ':param2' => 42, ':param3' => 'primary'],
            $this->compiledParams($builder),
        );
        self::assertSame(0, $builder->replacementCalls);
    }

    public function testNamedBindingsPreserveSupportedDatabaseValueObjects(): void
    {
        $builder = new CountingQueryBuilder();
        (new ReflectionClass($builder))->getProperty('driver')->setValue($builder, 'pgsql');
        $vector = new Vector([1, 0, 0]);

        $builder->raw('SELECT :embedding', ['embedding' => $vector])->getQuerySql();

        $params = $this->compiledParams($builder);
        self::assertCount(1, $params);
        self::assertSame($vector, array_values($params)[0]);
    }

    public function testNoBindingFastPathExecutesAgainstSqlite(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }

        $connection = new PDOConnection(new DatabaseSettings('sqlite', '', ':memory:', 0, '', ''));
        try {
            $pdo = $connection->pdo();
            $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)');
            $pdo->exec("INSERT INTO items (id, name) VALUES (1, 'one'), (2, 'two')");

            $result = (new QueryBuilder($connection))
                ->select('items', ['id', 'name'])
                ->orderBy('id')
                ->execute();

            self::assertSame(
                [['id' => 1, 'name' => 'one'], ['id' => 2, 'name' => 'two']],
                iterator_to_array($result->data, false),
            );
        } finally {
            $connection->disconnect();
        }
    }

    /** @return array<string, mixed> */
    private function compiledParams(QueryBuilderOperations $builder): array
    {
        return (new ReflectionClass($builder))->getProperty('compiledQueryParams')->getValue($builder);
    }
}

final class CountingQueryBuilder extends QueryBuilderOperations
{
    public int $replacementCalls = 0;

    protected function replaceSqlPlaceholders(string $sql, callable $replacement): string
    {
        $this->replacementCalls++;
        return parent::replaceSqlPlaceholders($sql, $replacement);
    }
}
