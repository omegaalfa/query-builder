<?php
declare(strict_types=1);
namespace Tests\QueryBuilder;
use Omegaalfa\QueryBuilder\Interfaces\{ConnectionInterface,PaginatorInterface};
use Omegaalfa\QueryBuilder\PostgreSQL\PgVector\{VectorMetric};
use Omegaalfa\QueryBuilder\QueryBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
final class PgVectorCacheKeyTest extends TestCase
{
    private function builder(): QueryBuilder { $c=$this->createMock(ConnectionInterface::class);$c->method('getDriver')->willReturn('pgsql');return new QueryBuilder($c,$this->createMock(PaginatorInterface::class)); }
    private function key(QueryBuilder $b): string {$m=new ReflectionMethod($b,'generateCacheKey');return $m->invoke($b);}
    private function vectorKey(array $v,VectorMetric $metric=VectorMetric::L2,int $limit=10,int $offset=0,int $tenant=7):string{return $this->key($this->builder()->select('documents',['id'])->nearestNeighbors('embedding',$v,$metric)->where('tenant_id','=',$tenant)->limit($limit,$offset));}
    public function testVectorCacheKeysAreStableDistinctAndCompact():void{self::assertSame($this->vectorKey([1,2,3]),$this->vectorKey([1,2,3]));self::assertNotSame($this->vectorKey([1,2,3]),$this->vectorKey([1,2,4]));self::assertLessThan(200,strlen($this->vectorKey(array_fill(0,1536,0.5))));}
    public function testLocaleDoesNotChangeVectorCacheKey():void{$before=setlocale(LC_NUMERIC,0);$key=$this->vectorKey([1.25,2.5,3.75]);setlocale(LC_NUMERIC,'C');self::assertSame($key,$this->vectorKey([1.25,2.5,3.75]));if(is_string($before))setlocale(LC_NUMERIC,$before);}
    public function testMetricDimensionsLimitOffsetAndFiltersParticipate():void{$base=$this->vectorKey([1,2,3]);self::assertNotSame($base,$this->vectorKey([1,2,3],VectorMetric::COSINE));self::assertNotSame($base,$this->vectorKey([1,2,3,4]));self::assertNotSame($base,$this->vectorKey([1,2,3],VectorMetric::L2,9));self::assertNotSame($base,$this->vectorKey([1,2,3],VectorMetric::L2,10,1));self::assertNotSame($base,$this->vectorKey([1,2,3],VectorMetric::L2,10,0,8));}
}
