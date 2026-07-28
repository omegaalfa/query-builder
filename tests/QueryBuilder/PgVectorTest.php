<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use InvalidArgumentException;
use Omegaalfa\QueryBuilder\Enums\OrderDirection;
use Omegaalfa\QueryBuilder\Exceptions\UnsupportedDatabaseFeatureException;
use Omegaalfa\QueryBuilder\Expressions\AliasedExpression;
use Omegaalfa\QueryBuilder\PostgreSQL\PgVector\Vector;
use Omegaalfa\QueryBuilder\PostgreSQL\PgVector\VectorDistance;
use Omegaalfa\QueryBuilder\PostgreSQL\PgVector\VectorMetric;
use Omegaalfa\QueryBuilder\QueryBuilderOperations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class PgVectorTest extends TestCase
{
    public function testVectorValidationAndCanonicalSerialization(): void
    {
        $vector = new Vector([1, 1.5, -2.0], 3);
        self::assertSame(3, $vector->dimensions());
        self::assertSame('[1,1.5,-2.0]', $vector->toPostgres());
        self::assertSame($vector->cacheValue(), (new Vector([1, 1.5, -2.0]))->cacheValue());
    }

    #[DataProvider('invalidVectors')]
    public function testInvalidVectorsAreRejected(array $values, ?int $dimensions = null): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Vector($values, $dimensions);
    }

    public static function invalidVectors(): iterable
    {
        yield 'empty' => [[]];
        yield 'non-list' => [[1 => 1.0]];
        yield 'string' => [[1.0, '0']];
        yield 'nan' => [[NAN]];
        yield 'positive infinity' => [[INF]];
        yield 'negative infinity' => [[-INF]];
        yield 'wrong dimensions' => [[1.0, 2.0], 3];
        yield 'invalid dimensions' => [[1.0], 0];
    }

    public function testEveryPhysicalExpressionOccurrenceGetsAUniquePlaceholder(): void
    {
        $builder = $this->postgresBuilder();
        $distance = VectorDistance::cosine('embedding', [1, 2, 3]);

        $sql = $builder
            ->select('documents', ['id', new AliasedExpression($distance, 'distance')])
            ->orderByExpression($distance, OrderDirection::ASC)
            ->limit(10)
            ->getQuerySql();

        self::assertSame(
            'SELECT "id", "embedding" <=> CAST(:expr0 AS vector) AS "distance" '
            . 'FROM "documents" ORDER BY "embedding" <=> CAST(:expr1 AS vector) ASC LIMIT 10 OFFSET 0',
            $sql,
        );
        self::assertSame(2, $this->parameterCount($builder));
    }

    #[DataProvider('metrics')]
    public function testNearestNeighborsUsesIndexableAscendingDistance(VectorMetric $metric): void
    {
        $sql = $this->postgresBuilder()
            ->select('documents', ['id'])
            ->nearestNeighbors('embedding', [1, 2, 3], $metric, 'distance')
            ->where('tenant_id', '=', 7)
            ->limit(5)
            ->getQuerySql();

        self::assertStringContainsString($metric->value . ' CAST(:expr0 AS vector)', $sql);
        self::assertStringContainsString('AS "distance"', $sql);
        self::assertStringContainsString('ORDER BY "embedding" ' . $metric->value . ' CAST(:expr1 AS vector) ASC', $sql);
        self::assertStringContainsString('WHERE "tenant_id" = :param2', $sql);
    }

    public static function metrics(): iterable
    {
        foreach (VectorMetric::cases() as $metric) {
            yield $metric->name => [$metric];
        }
    }

    public function testVectorInsertAndUpdateUseBoundCast(): void
    {
        $builder = $this->postgresBuilder();
        self::assertSame(
            'INSERT INTO "documents" (embedding) VALUES (CAST(:embedding AS vector))',
            $builder->insert('documents', ['embedding' => new Vector([1, 2, 3])])->getQuerySql(),
        );
        self::assertSame(
            'UPDATE "documents" SET "embedding" = CAST(:embedding AS vector)',
            $builder->update('documents', ['embedding' => new Vector([3, 2, 1])])->getQuerySql(),
        );
    }

    public function testPgVectorFailsEarlyOnOtherDrivers(): void
    {
        $this->expectException(UnsupportedDatabaseFeatureException::class);
        (new QueryBuilderOperations())->select('documents')->nearestNeighbors('embedding', [1, 2, 3]);
    }

    private function postgresBuilder(): QueryBuilderOperations
    {
        return new class extends QueryBuilderOperations {
            public function __construct()
            {
                $this->setDriver('pgsql');
            }
        };
    }

    private function parameterCount(QueryBuilderOperations $builder): int
    {
        $property = new ReflectionProperty(QueryBuilderOperations::class, 'params');
        return count($property->getValue($builder));
    }
}
