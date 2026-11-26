<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\QueryBuilder;
use Omegaalfa\QueryBuilder\SqlCompilationContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqlCompilationContextBindingRegressionTest extends TestCase
{
    public function testSequentialBindingsPreserveTypesOrderReferencesAndSnapshots(): void
    {
        $params = [];
        $context = new SqlCompilationContext($params, 'sqlite', static fn (string $name): string => $name);
        $object = new \stdClass();
        $values = [null, false, true, 17, 1.25, '0017', '', $object];
        $expected = [];
        for ($i = 0; $i < 20000; ++$i) {
            $value = $values[$i % count($values)];
            self::assertSame(':param' . $i, $context->bind($value));
            $expected[':param' . $i] = $value;
        }
        self::assertSame($expected, $params);
        $snapshot = $params;
        self::assertSame(':param20000', $context->bind('last'));
        self::assertSame($expected, $snapshot);
        self::assertSame('last', $params[':param20000']);
    }

    public function testIndependentContextsAndExistingPlaceholderCollisionRetainCurrentSemantics(): void
    {
        $first = [':param0' => 'old', ':custom' => false];
        $second = [];
        $a = new SqlCompilationContext($first, 'sqlite', static fn (string $name): string => $name);
        $b = new SqlCompilationContext($second, 'sqlite', static fn (string $name): string => $name);
        self::assertSame(':param0', $a->bind(7));
        self::assertSame(':param0', $b->bind('other'));
        self::assertSame([':param0' => 7, ':custom' => false], $first);
        self::assertSame([':param0' => 'other'], $second);
        $first = [];
        self::assertSame(':param1', $a->bind(null));
        self::assertSame([':param1' => null], $first);
    }

    public static function drivers(): array
    {
        return [['sqlite'], ['mysql'], ['pgsql']];
    }

    #[DataProvider('drivers')]
    public function testBatchCteSubqueryRawAndBuilderReuse(string $driver): void
    {
        if (!extension_loaded('pdo_' . $driver)) {
            self::markTestSkipped('PDO driver unavailable.');
        }
        $env = $driver === 'mysql' ? 'DOCKER_MYSQL_' : 'PGVECTOR_';
        if ($driver !== 'sqlite' && !getenv($env . 'HOST')) {
            self::markTestSkipped('Compose database not configured.');
        }
        $connection = new PDOConnection(new DatabaseSettings(
            $driver,
            getenv($env . 'HOST') ?: '',
            $driver === 'sqlite' ? ':memory:' : (string) getenv($env . 'DATABASE'),
            (int) getenv($env . 'PORT'),
            getenv($env . 'USERNAME') ?: '',
            getenv($env . 'PASSWORD') ?: '',
        ));
        try {
            $connection->pdo()->exec('CREATE TEMPORARY TABLE binding_regression (id INTEGER PRIMARY KEY, value VARCHAR(40))');
            $builder = new QueryBuilder($connection);
            $rows = [];
            for ($i = 1; $i <= 1000; ++$i) {
                $rows[] = ['id' => $i, 'value' => 'value-' . $i];
            }
            self::assertSame(1000, $builder->insertBatch('binding_regression', $rows)->execute()->count);
            $actual = $connection->pdo()->query('SELECT id, value FROM binding_regression ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
            self::assertEquals($rows, $actual);
            $builder->select('source', ['id', 'value'])
                ->where('value', '=', 'value-7')
                ->with('source', 'SELECT id, value FROM binding_regression WHERE id = :param0', ['param0' => 7])
                ->whereInSubquery('id', 'SELECT 7 WHERE :param0 = 7', ['param0' => 7]);
            $sql = $builder->getQuerySql();
            self::assertSame($sql, $builder->getQuerySql());
            foreach ([':param0', ':param1', ':param2'] as $placeholder) {
                self::assertSame(1, substr_count($sql, $placeholder));
            }
            self::assertSame(
                [':param0' => 7, ':param1' => 'value-7', ':param2' => 7],
                (new \ReflectionMethod($builder, 'currentQueryParams'))->invoke($builder),
            );
            self::assertSame([['id' => 7, 'value' => 'value-7']], iterator_to_array($builder->execute()->data, false));
            self::assertSame([['id' => 8]], iterator_to_array($builder->select('binding_regression', ['id'])->where('id', '=', 8)->execute()->data, false));
            self::assertSame([['id' => 9]], iterator_to_array($builder->raw('SELECT id FROM binding_regression WHERE id = :id', ['id' => 9])->execute()->data, false));
            $independent = new QueryBuilder($connection);
            self::assertSame([['id' => 10]], iterator_to_array($independent->select('binding_regression', ['id'])->where('id', '=', 10)->execute()->data, false));
        } finally {
            $connection->disconnect();
        }
    }
}