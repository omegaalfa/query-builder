<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Generator;
use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\PostgreSQL\PgVector\Vector;
use Omegaalfa\QueryBuilder\PostgreSQL\PgVector\VectorMetric;
use Omegaalfa\QueryBuilder\QueryBuilder;
use Omegaalfa\QueryBuilder\Utils\EnvLoader;
use PDO;
use PHPUnit\Framework\TestCase;

final class PgVectorIntegrationTest extends TestCase
{
    private PDOConnection $connection;
    private QueryBuilder $builder;
    private PDO $pdo;

    protected function setUp(): void
    {
        if (getenv('PGVECTOR_INTEGRATION') !== '1') {
            self::markTestSkipped('Set PGVECTOR_INTEGRATION=1 to run against PostgreSQL/pgvector.');
        }

        EnvLoader::load(dirname(__DIR__, 2), required: true);

        $this->connection = new PDOConnection(new DatabaseSettings(
            driver: 'pgsql',
            host: EnvLoader::get('PGVECTOR_HOST', '127.0.0.1'),
            database: EnvLoader::require('PGVECTOR_DATABASE'),
            port: EnvLoader::getInt('PGVECTOR_PORT', 54329) ?? 54329,
            username: EnvLoader::require('PGVECTOR_USERNAME'),
            password: EnvLoader::require('PGVECTOR_PASSWORD'),
        ));
        $this->connection->connect();
        $this->pdo = $this->connection->pdo();
        $this->pdo->exec('CREATE EXTENSION IF NOT EXISTS vector');
        $this->pdo->exec('DROP TABLE IF EXISTS documents');
        $this->pdo->exec(
            'CREATE TABLE documents ('
            . 'id bigserial PRIMARY KEY, tenant_id bigint NOT NULL, content text NOT NULL UNIQUE, '
            . 'embedding vector(3) NOT NULL)'
        );
        $this->builder = new QueryBuilder($this->connection);
    }

    public function testNativePreparedStatementsAndVectorOperations(): void
    {
        self::assertFalse($this->pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES));
        self::assertSame(PDO::ERRMODE_EXCEPTION, $this->pdo->getAttribute(PDO::ATTR_ERRMODE));
        self::assertStringStartsWith('PostgreSQL 17.', (string) $this->pdo->query('SELECT version()')->fetchColumn());
        self::assertSame('0.8.5', $this->pdo->query(
            "SELECT extversion FROM pg_extension WHERE extname = 'vector'"
        )->fetchColumn());

        foreach ([
            [1, 'origin', [0, 0, 0]],
            [1, 'x', [1, 0, 0]],
            [1, 'xy', [1, 1, 0]],
            [2, 'other', [0.2, 0.2, 0.2]],
        ] as [$tenant, $content, $embedding]) {
            $this->builder->insert('documents', [
                'tenant_id' => $tenant,
                'content' => $content,
                'embedding' => new Vector($embedding, 3),
            ])->execute();
        }

        $this->builder->update('documents', ['embedding' => new Vector([2, 0, 0], 3)])
            ->where('content', '=', 'x')
            ->execute();

        foreach (VectorMetric::cases() as $metric) {
            $query = $this->builder->select('documents', ['id', 'content'])
                ->nearestNeighbors('embedding', [1, 0, 0], $metric, 'distance', 3)
                ->where('tenant_id', '=', 1)
                ->limit(2);
            $sql = $query->getQuerySql();
            self::assertSame(1, substr_count($sql, ':expr0'));
            self::assertSame(1, substr_count($sql, ':expr1'));
            $rows = iterator_to_array($query->execute(true)->data, false);
            self::assertCount(2, $rows);
            self::assertLessThanOrEqual((float) $rows[1]['distance'], (float) $rows[0]['distance']);
        }

        $negative = iterator_to_array($this->builder->select('documents', ['id'])
            ->nearestNeighbors('embedding', [1, 0, 0], VectorMetric::INNER_PRODUCT)
            ->limit(1)->execute()->data, false);
        self::assertSame(-2.0, (float) $negative[0]['distance']);

        $explain = $this->builder->select('documents', ['id'])
            ->nearestNeighbors('embedding', [1, 0, 0])->limit(2)->explain();
        self::assertNotEmpty($explain);

        $streamed = $this->builder->select('documents', ['id'])
            ->nearestNeighbors('embedding', [1, 0, 0])->limit(2)->execute(false);
        self::assertInstanceOf(Generator::class, $streamed->data);
        self::assertCount(2, iterator_to_array($streamed->data, false));
    }

    public function testTypedBatchUpsertWithVectors(): void
    {
        $this->builder->insertBatch('documents', [[
            'tenant_id' => 1,
            'content' => 'chunk-a',
            'embedding' => new Vector([1, 0, 0], 3),
        ], [
            'tenant_id' => 1,
            'content' => 'chunk-b',
            'embedding' => new Vector([0, 1, 0], 3),
        ]])->onConflict(['content'])->doUpdate(['tenant_id', 'embedding'])->execute();

        $this->builder->insertBatch('documents', [[
            'tenant_id' => 9,
            'content' => 'chunk-a',
            'embedding' => new Vector([0, 0, 1], 3),
        ], [
            'tenant_id' => 2,
            'content' => 'chunk-c',
            'embedding' => new Vector([1, 1, 0], 3),
        ]])->onConflict(['content'])->doUpdate(['tenant_id', 'embedding'])->execute();

        $rows = $this->pdo->query(
            'SELECT tenant_id, content, embedding::text AS embedding FROM documents ORDER BY content'
        )->fetchAll(PDO::FETCH_ASSOC);

        self::assertCount(3, $rows);
        self::assertSame('9', (string) $rows[0]['tenant_id']);
        self::assertSame('[0,0,1]', $rows[0]['embedding']);
    }

    public function testInsertIdentityStrategiesWithoutArtificialSequence(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS identity_rfc');
        $this->pdo->exec('CREATE TABLE identity_rfc ('
            . 'tenant_id text NOT NULL, external_id uuid NOT NULL, content text NOT NULL, '
            . 'PRIMARY KEY (tenant_id, external_id))');

        $uuid = '018f47ac-10ab-7c1d-a111-111111111111';
        $plain = $this->builder->insert('identity_rfc', [
            'tenant_id' => 'tenant-a',
            'external_id' => $uuid,
            'content' => 'first',
        ])->execute();
        self::assertFalse($plain->insertId);
        self::assertFalse($this->builder->getInsertId());

        $returned = $this->builder->insert('identity_rfc', [
            'tenant_id' => 'tenant-b',
            'external_id' => $uuid,
            'content' => 'second',
        ])->returning(['tenant_id', 'external_id'])->execute();
        $rows = iterator_to_array($returned->data, false);
        self::assertSame('tenant-b', $rows[0]['tenant_id']);
        self::assertSame($uuid, $rows[0]['external_id']);

        $upsert = $this->builder->insert('identity_rfc', [
            'tenant_id' => 'tenant-a',
            'external_id' => $uuid,
            'content' => 'updated',
        ])->onConflict(['tenant_id', 'external_id'])->doUpdate(['content'])->execute();
        self::assertFalse($upsert->insertId);
        self::assertSame('updated', $this->pdo->query(
            "SELECT content FROM identity_rfc WHERE tenant_id = 'tenant-a'"
        )->fetchColumn());
    }

    public function testPostgreSqlReturnLastInsertIdUsesReturningInsteadOfPdoLastInsertId(): void
    {
        $query = $this->builder->insert('documents', [
            'tenant_id' => 1,
            'content' => 'generated-id',
            'embedding' => new Vector([0, 0, 1], 3),
        ])->returnLastInsertId();

        self::assertStringEndsWith('RETURNING "id"', $query->getQuerySql());
        $result = $query->execute();

        self::assertNotFalse($result->insertId);
        self::assertSame($result->insertId, $this->builder->getInsertId());
    }
}
