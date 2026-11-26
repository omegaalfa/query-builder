<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\Enums\SqlOperator;
use Omegaalfa\QueryBuilder\QueryBuilderOperations;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class IdentifierNormalizationRegressionTest extends TestCase
{
    public function testSelectKeepsIdentifiersExpressionsAndAliasesUnchanged(): void
    {
        $builder = new QueryBuilderOperations();

        $sql = $builder->select('accounts', [
            'id',
            'u.name',
            '*',
            'u.*',
            'COUNT(*) AS total',
            'name AS display',
            'lower(name) as lower_name',
        ])->getQuerySql();

        self::assertSame(
            'SELECT `id`, `u`.`name`, *, `u`.*, COUNT(*) AS total, name AS display, lower(name) as lower_name FROM `accounts`',
            $sql,
        );
    }

    public function testSelectPreservesWhitespaceNormalizationAndIdentifierEscaping(): void
    {
        $builder = new QueryBuilderOperations();

        $sql = $builder->select('records', [
            "first\tAS\tlabel",
            'odd`name',
            'name;DROP',
        ])->getQuerySql();

        self::assertSame(
            'SELECT `first` AS `label`, `odd``name`, `name;DROP` FROM `records`',
            $sql,
        );
    }

    public function testWhereQuotesQualifiedIdentifiersAndPreservesBindings(): void
    {
        $builder = new QueryBuilderOperations();

        $sql = $builder->select('records')
            ->where('owner.id', SqlOperator::EQUALS, 42)
            ->where('status', '>=', false)
            ->getQuerySql();

        self::assertSame(
            'SELECT * FROM `records` WHERE `owner`.`id` = :param0 AND `status` >= :param1',
            $sql,
        );
        self::assertSame([':param0' => 42, ':param1' => false], $this->bindings($builder));
    }

    public function testWhereDoesNotTreatFunctionOrAliasTextAsSelectExpressions(): void
    {
        $builder = new QueryBuilderOperations();

        $sql = $builder->select('records')
            ->where('COUNT(*)', SqlOperator::EQUALS, 1)
            ->getQuerySql();

        self::assertSame('SELECT * FROM `records` WHERE `COUNT(*)` = :param0', $sql);
        self::assertSame([':param0' => 1], $this->bindings($builder));
    }

    public function testSelectAndWhereKeepDialectSpecificQuoting(): void
    {
        foreach ([
            'mysql' => '`',
            'pgsql' => '"',
            'sqlite' => '"',
        ] as $driver => $quote) {
            $builder = new QueryBuilderOperations();
            (new ReflectionClass($builder))->getProperty('driver')->setValue($builder, $driver);

            $sql = $builder->select('records', ['owner.id', 'COUNT(*) AS total'])
                ->where('owner.active', SqlOperator::EQUALS, true)
                ->getQuerySql();

            self::assertSame(
                sprintf(
                    'SELECT %1$sowner%1$s.%1$sid%1$s, COUNT(*) AS total FROM %1$srecords%1$s WHERE %1$sowner%1$s.%1$sactive%1$s = :param0',
                    $quote,
                ),
                $sql,
                $driver,
            );
            self::assertSame([':param0' => true], $this->bindings($builder), $driver);
        }
    }

    /** @return array<string, mixed> */
    private function bindings(QueryBuilderOperations $builder): array
    {
        return (new ReflectionClass($builder))->getProperty('params')->getValue($builder);
    }
}