<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\QueryBuilderOperations;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PlaceholderScannerOptimizationTest extends TestCase
{
    public function testProtectedSqlRegionsRemainByteForByteUnchanged(): void
    {
        foreach (['mysql', 'pgsql', 'sqlite'] as $driver) {
            $builder = new ScannerProbe();
            (new ReflectionClass($builder))->getProperty('driver')->setValue($builder, $driver);
            $sql = "SELECT '?' AS literal, \"?\" AS quoted, [ ? ] AS bracket\n"
                . "/* :ignored ? */ WHERE id = ? AND code = :name";
            $calls = [];

            $result = $builder->scan($sql, static function (string $placeholder) use (&$calls): string {
                $calls[] = $placeholder;
                return '[' . $placeholder . ']';
            });

            self::assertSame(
                "SELECT '?' AS literal, \"?\" AS quoted, [ ? ] AS bracket\n"
                    . "/* :ignored ? */ WHERE id = [?] AND code = [:name]",
                $result,
                $driver,
            );
            self::assertSame(['?', ':name'], $calls, $driver);
        }
    }

    public function testPositionalNamedAndRepeatedPlaceholdersKeepTheirTokens(): void
    {
        $builder = new ScannerProbe();
        $calls = [];

        $result = $builder->scan(
            'SELECT * FROM items WHERE a = ? OR b = :name OR c = :name',
            static function (string $placeholder) use (&$calls): string {
                $calls[] = $placeholder;
                return ':compiled' . count($calls);
            },
        );

        self::assertSame(
            'SELECT * FROM items WHERE a = :compiled1 OR b = :compiled2 OR c = :compiled3',
            $result,
        );
        self::assertSame(['?', ':name', ':name'], $calls);
    }

    public function testDialectSpecificCommentAndDollarQuoteRulesRemainIntact(): void
    {
        $mysql = new ScannerProbe();
        (new ReflectionClass($mysql))->getProperty('driver')->setValue($mysql, 'mysql');
        self::assertSame(
            "SELECT a - -b, a = :compiled # :ignored\n WHERE id = :compiled",
            $mysql->scan(
                "SELECT a - -b, a = ? # :ignored\n WHERE id = ?",
                static fn(string $placeholder): string => ':compiled',
            ),
        );

        $pgsql = new ScannerProbe();
        (new ReflectionClass($pgsql))->getProperty('driver')->setValue($pgsql, 'pgsql');
        self::assertSame(
            'SELECT $tag$? :ignored$tag$ AS body, id = :compiled',
            $pgsql->scan(
                "SELECT \$tag\$? :ignored\$tag\$ AS body, id = ?",
                static fn(string $placeholder): string => ':compiled',
            ),
        );
    }

    public function testCteBindingsUseTheSameOptimizedScannerSemantics(): void
    {
        $builder = new ScannerProbe();
        (new ReflectionClass($builder))->getProperty('driver')->setValue($builder, 'sqlite');

        self::assertSame(
            'WITH "source" AS (SELECT id FROM items WHERE tenant_id = :param0 AND status = :param1) '
                . 'SELECT "id" FROM "source" WHERE "id" = :param2',
            $builder->with(
                'source',
                'SELECT id FROM items WHERE tenant_id = :tenant AND status = :status',
                ['tenant' => 7, 'status' => 'active'],
            )->select('source', ['id'])->where('id', '=', 7)->getQuerySql(),
        );
        self::assertSame(
            [':param0' => 7, ':param1' => 'active', ':param2' => 7],
            (new ReflectionClass($builder))->getProperty('compiledQueryParams')->getValue($builder),
        );
    }
}

final class ScannerProbe extends QueryBuilderOperations
{
    public function scan(string $sql, callable $replacement): string
    {
        return $this->replaceSqlPlaceholders($sql, $replacement);
    }
}
