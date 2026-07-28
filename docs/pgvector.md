# Optional pgvector support

The pgvector integration is PostgreSQL-specific and adds no runtime dependency. It was validated against the published `pgvector/pgvector:0.8.5-pg17-bookworm` image, pinned by digest in the optional Docker Compose file. The effective extension version must still be verified from the database. The base query builder remains usable when the extension is absent; pgvector SQL is emitted only when its API is called. Applications can explicitly probe it once with:

Copy `.env.example` to `.env` before starting the optional environment. Docker Compose and `vectorPgsql.php` read the same `PGVECTOR_*` values; credentials are not stored in the Compose file.

```sql
SELECT extversion FROM pg_extension WHERE extname = 'vector';
```

Enable the extension as a deployment or migration operation, never as part of a normal query:

```sql
CREATE EXTENSION vector;
CREATE TABLE documents (
    id bigserial PRIMARY KEY,
    tenant_id bigint NOT NULL,
    content text NOT NULL,
    embedding vector(3) NOT NULL
);
```

## Writing vectors

```php
use Omegaalfa\QueryBuilder\PostgreSQL\PgVector\Vector;

$embedding = new Vector([0.1, 0.2, 0.3], dimensions: 3);
$qb->insert('documents', [
    'tenant_id' => 42,
    'content' => 'Example',
    'embedding' => $embedding,
])->execute();

$qb->update('documents', ['embedding' => new Vector([0.3, 0.2, 0.1], 3)])
    ->where('id', '=', 1)
    ->execute();

$qb->insertBatch('documents', [
    ['id' => 1, 'tenant_id' => 42, 'content' => 'Updated', 'embedding' => new Vector([0.2, 0.3, 0.4], 3)],
    ['id' => 2, 'tenant_id' => 42, 'content' => 'New', 'embedding' => new Vector([0.4, 0.3, 0.2], 3)],
])->onConflict(['id'])
    ->doUpdate(['content', 'embedding'])
    ->execute();
```

The vector is serialized canonically, bound as `PDO::PARAM_STR`, and used as `CAST(:embedding AS vector)`. It is never concatenated into SQL.

## Nearest-neighbor queries

```php
use Omegaalfa\QueryBuilder\PostgreSQL\PgVector\VectorMetric;

$result = $qb
    ->select('documents', ['id', 'content'])
    ->nearestNeighbors(
        column: 'embedding',
        vector: [0.1, 0.2, 0.3],
        metric: VectorMetric::COSINE,
        alias: 'distance',
        dimensions: 3,
    )
    ->where('tenant_id', '=', 42)
    ->limit(10)
    ->execute();
```

`limit()` only adds `LIMIT/OFFSET`; it does not execute a `COUNT`. Use
`paginate($perPage, $currentPage)` only when the result must include total and
page metadata, since pagination executes a count query followed by the limited
query. For top-k vector search, `limit()` is normally the appropriate choice.

This generates the indexable ordering form:

```sql
SELECT "id", "content", "embedding" <=> CAST(:expr0 AS vector) AS "distance"
FROM "documents"
WHERE "tenant_id" = :param2
ORDER BY "embedding" <=> CAST(:expr1 AS vector) ASC
LIMIT 10 OFFSET 0
```

Supported dense metrics are L2 (`<->`), negative inner product (`<#>`), cosine distance (`<=>`), and L1 (`<+>`). All are distances ordered ascending. In particular, `<#>` is the negative inner product. Cosine similarity is `1 - cosine_distance`; it is not returned by this API and should not replace the direct distance expression used for index ordering.

For explicit composition:

```php
use Omegaalfa\QueryBuilder\Enums\OrderDirection;
use Omegaalfa\QueryBuilder\Expressions\AliasedExpression;
use Omegaalfa\QueryBuilder\PostgreSQL\PgVector\VectorDistance;

$distance = VectorDistance::l2('embedding', [1, 2, 3]);
$qb->select('documents', ['id', new AliasedExpression($distance, 'distance')])
    ->orderByExpression($distance, OrderDirection::ASC)
    ->limit(10);

$sql = $qb->getQuerySql();
$debugSql = $qb->toSql(true); // debugging only
$plan = $qb->explain();       // executes EXPLAIN ANALYZE on PostgreSQL
```

Calling these APIs on another PDO driver throws `UnsupportedDatabaseFeatureException`. PostgreSQL without pgvector continues to work for ordinary queries and fails only if pgvector SQL is executed.

## Exact and approximate search

The query API does not depend on an index. Without one, PostgreSQL performs exact search. HNSW and IVFFlat are schema/performance concerns and require a separate operator-class index per metric, for example:

```sql
CREATE INDEX CONCURRENTLY documents_embedding_cosine_hnsw
ON documents USING hnsw (embedding vector_cosine_ops)
WITH (m = 16, ef_construction = 64);

CREATE INDEX CONCURRENTLY documents_embedding_cosine_ivfflat
ON documents USING ivfflat (embedding vector_cosine_ops)
WITH (lists = 100);
```

HNSW supports `vector` indexes up to 2,000 dimensions; IVFFlat has the same indexed `vector` limit. Approximate indexes trade recall for speed. The local 10,000-row/384-dimension baseline showed exact scans around 5.2 ms and HNSW around 2.6-2.8 ms, so exact search remains a sensible baseline for small collections. HNSW is the initial ANN recommendation when latency or collection size requires an index; measure recall with representative data before adopting it.

Filtered ANN queries should have normal B-tree indexes for selective relational predicates such as tenant, collection, and status. PostgreSQL may correctly prefer such an index plus exact distance ordering for very selective filters. The benchmark covers global, 50%, 10%, 1%, and 0.1% selectivity and reports the actual plan and recall. pgvector iterative scans can improve result counts after filtering, but query-local tuning is still outside this API because safe use requires transaction-scoped `SET LOCAL` support.

## Testing

```bash
cp .env.example .env
composer test
docker compose up -d --wait pgvector
php vectorPgsql.php
php benchmarks/pgvector.php
composer benchmark:pgvector-overhead
BENCH_ROWS=10000 BENCH_DIMENSIONS=384 composer benchmark:pgvector-search
composer benchmark:database
docker compose down
```

The detailed methodology, parameters, units, build cost, indexed-ingestion cost, filtered ANN plans, and recall results are in [Benchmarks](benchmarks.md). These are PHP/client and local-database measurements, not universal pgvector results.

See [Environment configuration](environment.md) for production permission checks and secret-injection guidance.


## Scope and limitations

The MVP supports dense `vector` values only. `halfvec`, `sparsevec`, `bit`, Hamming/Jaccard, index builders, migrations, extension installation, and query-local ANN tuning are future work. The package does not generate embeddings, call model APIs, implement RAG, chunk documents, rerank results, or manage models. Applications must use mutually compatible embeddings.
