<?php
declare(strict_types=1);
namespace Tests\QueryBuilder;
use Omegaalfa\QueryBuilder\PostgreSQL\PgVector\VectorMetric;
use PHPUnit\Framework\TestCase;
final class PgVectorMetricTest extends TestCase
{
    public function testInnerProductMetricReturnsNegativeInnerProduct():void{self::assertSame('<#>',VectorMetric::INNER_PRODUCT->value);}
}
