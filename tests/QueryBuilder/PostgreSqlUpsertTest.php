<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\Exceptions\QueryException;
use Omegaalfa\QueryBuilder\Exceptions\UnsupportedDatabaseFeatureException;
use Omegaalfa\QueryBuilder\PostgreSQL\PgVector\Vector;
use Omegaalfa\QueryBuilder\QueryBuilderOperations;
use PHPUnit\Framework\TestCase;

final class PostgreSqlUpsertTest extends TestCase
{
    public function testBuildsTypedBatchUpsertWithVectorCasts(): void
    {
        $builder = $this->postgresBuilder();
        $sql = $builder->insertBatch('chunks', [[
            'external_id' => 'chunk-1',
            'content' => 'content',
            'embedding' => new Vector([1, 2, 3], 3),
        ]])
            ->onConflict(['external_id'])
            ->doUpdate(['content', 'embedding'])
            ->getQuerySql();

        self::assertSame(
            'INSERT INTO "chunks" ("external_id", "content", "embedding") VALUES '
            . '(:param0, :param1, CAST(:param2 AS vector)) '
            . 'ON CONFLICT ("external_id") DO UPDATE SET '
            . '"content" = EXCLUDED."content", "embedding" = EXCLUDED."embedding"',
            $sql,
        );
    }

    public function testBuildsDoNothing(): void
    {
        $sql = $this->postgresBuilder()
            ->insertBatch('chunks', [['external_id' => 'chunk-1']])
            ->onConflict(['external_id'])
            ->doNothing()
            ->getQuerySql();

        self::assertStringEndsWith('ON CONFLICT ("external_id") DO NOTHING', $sql);
    }

    public function testRejectsColumnOutsideInsertedRows(): void
    {
        $this->expectException(QueryException::class);
        $this->postgresBuilder()
            ->insertBatch('chunks', [['external_id' => 'chunk-1']])
            ->onConflict(['missing']);
    }

    public function testRejectsConflictActionBeforeTarget(): void
    {
        $this->expectException(QueryException::class);
        $this->postgresBuilder()
            ->insertBatch('chunks', [['external_id' => 'chunk-1']])
            ->doNothing();
    }

    public function testRejectsOnConflictOutsidePostgreSql(): void
    {
        $this->expectException(UnsupportedDatabaseFeatureException::class);
        (new QueryBuilderOperations())
            ->insertBatch('chunks', [['external_id' => 'chunk-1']])
            ->onConflict(['external_id']);
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
}
