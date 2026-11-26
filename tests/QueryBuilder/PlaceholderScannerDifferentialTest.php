<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\QueryBuilderOperations;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PlaceholderScannerDifferentialTest extends TestCase
{
    public function testCompatibilityCorpusPreservesThePreviousTokenRules(): void
    {
        foreach (['mysql', 'pgsql', 'sqlite'] as $driver) {
            $probe = new DifferentialScannerProbe();
            (new ReflectionClass($probe))->getProperty('driver')->setValue($probe, $driver);
            $calls = [];
            $sql = "SELECT 'text ? :name', \"quoted :name ?\", `backtick ?`, [bracket :name ?] "
                . "FROM items WHERE a = ? AND b = :name AND c = :name "
                . "/* ? :ignored */ -- ? :ignored\n AND casted::text = ?";

            $compiled = $probe->scan($sql, static function (string $token) use (&$calls): string {
                $calls[] = $token;
                return '<' . $token . '>';
            });

            self::assertSame(
                "SELECT 'text ? :name', \"quoted :name ?\", `backtick ?`, [bracket :name ?] "
                    . "FROM items WHERE a = <?> AND b = <:name> AND c = <:name> "
                    . "/* ? :ignored */ -- ? :ignored\n AND casted::text = <?>",
                $compiled,
                $driver,
            );
            self::assertSame(['?', ':name', ':name', '?'], $calls, $driver);
        }
    }

    public function testDriverSpecificEscapesAndOperatorsRemainCompatible(): void
    {
        $mysql = new DifferentialScannerProbe();
        (new ReflectionClass($mysql))->getProperty('driver')->setValue($mysql, 'mysql');
        self::assertSame(
            "SELECT 'it\\'s ?' AS value # :ignored\n WHERE id = <?>",
            $mysql->scan(
                "SELECT 'it\\'s ?' AS value # :ignored\n WHERE id = ?",
                static fn(string $token): string => '<' . $token . '>',
            ),
        );

        $pgsql = new DifferentialScannerProbe();
        (new ReflectionClass($pgsql))->getProperty('driver')->setValue($pgsql, 'pgsql');
        self::assertSame(
            "SELECT E'it\\'s ?' AS value, \$tag\$? :ignored\$tag\$ AS body, "
                . "payload <?> <?>| <?>&",
            $pgsql->scan(
                "SELECT E'it\\'s ?' AS value, \$tag\$? :ignored\$tag\$ AS body, "
                    . "payload ? ?| ?&",
                static fn(string $token): string => '<' . $token . '>',
            ),
        );
    }

    public function testMissingAndExtraParametersKeepExistingBindingSemanticsAndTypes(): void
    {
        $probe = new DifferentialScannerProbe();
        $source = [
            ':enabled' => true,
            ':missing' => null,
            ':count' => 42,
            ':label' => 'active',
            ':extra' => 'unused',
        ];
        $compiled = [];

        $sql = $probe->scan(
            'SELECT :enabled, :missing, :count, :label, :unknown',
            static function (string $token) use ($source, &$compiled): ?string {
                if (!array_key_exists($token, $source)) {
                    return null;
                }
                $placeholder = ':param' . count($compiled);
                $compiled[$placeholder] = $source[$token];
                return $placeholder;
            },
        );

        self::assertSame(
            'SELECT :param0, :param1, :param2, :param3, :unknown',
            $sql,
        );
        self::assertSame(
            [':param0' => true, ':param1' => null, ':param2' => 42, ':param3' => 'active'],
            $compiled,
        );
        self::assertSame('unused', $source[':extra']);
    }
}

final class DifferentialScannerProbe extends QueryBuilderOperations
{
    public function scan(string $sql, callable $replacement): string
    {
        return $this->replaceSqlPlaceholders($sql, $replacement);
    }
}
