<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\Exceptions\QueryException;
use Omegaalfa\QueryBuilder\QueryBuilderOperations;
use PHPUnit\Framework\TestCase;

final class SQLiteBatchIncrementalCompilationTest extends TestCase
{
    public function testWholeBatchCompilationMatchesCurrentSqlAndBindings(): void
    {
        $builder = $this->createBuilder();
        $rows = [
            ['name' => 'first', 'quantity' => 10],
            ['name' => 'second', 'quantity' => 20],
            ['name' => 'third', 'quantity' => 30],
        ];
        $builder->insertBatch('items', $rows);
        $fullSql = $builder->getQuerySql();
        $fullParams = $builder->paramsForTest();
        self::assertSame([], $fullParams);

        $compiled = $builder->compileChunkForTest(0, count($rows));

        self::assertSame($fullSql, $compiled['sql']);
        self::assertSame([
            ':param0' => 'first',
            ':param1' => 10,
            ':param2' => 'second',
            ':param3' => 20,
            ':param4' => 'third',
            ':param5' => 30,
        ], $compiled['params']);
        self::assertSame($fullSql, $builder->getQuerySql());
        self::assertSame($fullParams, $builder->paramsForTest());
    }

    public function testRangesCompileIndependentlyWithLocalPlaceholderNumbering(): void
    {
        $builder = $this->createBuilder();
        $rows = [
            ['name' => 'first', 'quantity' => 10],
            ['name' => 'second', 'quantity' => 20],
            ['name' => 'third', 'quantity' => 30],
        ];
        $builder->insertBatch('items', $rows);
        $originalSql = $builder->getQuerySql();
        $originalParams = $builder->paramsForTest();
        self::assertSame([], $originalParams);

        $first = $builder->compileChunkForTest(0, 2);
        $last = $builder->compileChunkForTest(2, 2);

        self::assertSame(
            'INSERT INTO "items" ("name", "quantity") VALUES (:param0, :param1), (:param2, :param3)',
            $first['sql'],
        );
        self::assertSame([':param0' => 'first', ':param1' => 10, ':param2' => 'second', ':param3' => 20], $first['params']);
        self::assertSame(
            'INSERT INTO "items" ("name", "quantity") VALUES (:param0, :param1)',
            $last['sql'],
        );
        self::assertSame([':param0' => 'third', ':param1' => 30], $last['params']);
        self::assertSame($originalSql, $builder->getQuerySql());
        self::assertSame($originalParams, $builder->paramsForTest());
    }

    public function testStoredRowsAreSnapshotByCopyOnWriteAndClearedOnReset(): void
    {
        $builder = $this->createBuilder();
        $rows = [['name' => 'original', 'quantity' => 1]];
        $builder->insertBatch('items', $rows);
        $rows[0]['name'] = 'mutated after builder call';

        $compiled = $builder->compileChunkForTest(0, 1);

        self::assertSame([':param0' => 'original', ':param1' => 1], $compiled['params']);
        $builder->resetForTest();

        $this->expectException(QueryException::class);
        $builder->compileChunkForTest(0, 1);
    }

    public function testInspectionBindingsCanBeMaterializedWithoutPersistingThem(): void
    {
        $builder = $this->createBuilder();
        $builder->insertBatch('items', [
            ['name' => 'first', 'quantity' => 1],
            ['name' => 'second', 'quantity' => 2],
        ]);

        self::assertSame([
            ':param0' => 'first',
            ':param1' => 1,
            ':param2' => 'second',
            ':param3' => 2,
        ], $builder->currentParamsForTest());
        self::assertSame([], $builder->paramsForTest());
    }

    public function testInvalidRangesAreRejectedWithoutChangingTheCurrentSql(): void
    {
        $builder = $this->createBuilder();
        $builder->insertBatch('items', [['name' => 'one']]);
        $sql = $builder->getQuerySql();

        try {
            $builder->compileChunkForTest(1, 1);
            self::fail('An offset outside the batch must be rejected.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('range is invalid', $exception->getMessage());
        }

        self::assertSame($sql, $builder->getQuerySql());
    }

    private function createBuilder(): SQLiteBatchCompilationProbe
    {
        return new SQLiteBatchCompilationProbe();
    }
}

final class SQLiteBatchCompilationProbe extends QueryBuilderOperations
{
    public function __construct()
    {
        $this->setDriver('sqlite');
    }

    /** @return array{sql: string, params: array<string, mixed>} */
    public function compileChunkForTest(int $offset, int $length): array
    {
        return $this->compileSQLiteBatchChunk($offset, $length);
    }

    /** @return array<string, mixed> */
    public function paramsForTest(): array
    {
        return $this->params;
    }

    /** @return array<string, mixed> */
    public function currentParamsForTest(): array
    {
        return $this->currentQueryParams();
    }

    public function resetForTest(): void
    {
        $this->resetOperationsState();
    }
}