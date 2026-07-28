# pgvector architecture decision and diagnosis

## Diagnosis

`QueryBuilder` extends the stateful `QueryBuilderOperations`. A constructor-provided `ConnectionInterface` supplies the PDO driver and connection; execution prepares `getQuerySql()`, infers basic PDO types, streams rows through a generator, optionally materializes them for cache, and resets operation state. Transactions and savepoints live in `PDOConnection`.

Query state is split among `sql`, `joins`, `where`, `orderBy`, `groupBy`, `having`, `limit`, and `params`. Named placeholders are derived from data keys or the current parameter count. Identifiers are quoted per driver by `HelperQueryOperationsTrait`. `getQuerySql()` concatenates the state; `toSql(true)` substitutes values for debugging; `explain()` prepares an explain query with the same parameters. Cloning is used only for pagination count.

The pre-change blockers were:

- `select()` distinguished expressions from identifiers using parentheses/`AS` string heuristics, which is not a safe expression model.
- `orderBy()` accepted identifiers only.
- no expression could own and reuse a bound placeholder in SELECT and ORDER BY.
- binding rejected arrays and had no typed database-value protocol.
- INSERT/UPDATE had no safe type cast for vector text input.
- the cache hashed only the base `sql` array, omitting assembled filters, ordering, limit, and offset; arbitrary objects were not canonicalized.
- debug and EXPLAIN did not understand typed database values.
- driver capability checks existed in several places but no feature-specific exception existed.

Existing architectural debt remains outside this change: `composer.json` says PHP 8.2 and includes mandatory Redis/Illuminate dependencies despite the requested PHP 8.4/zero-extra-dependency direction; `ConnectionInterface::pdo()` does not declare the buffered argument passed by `QueryBuilder`; select string-expression heuristics and raw APIs remain trusted escape hatches; single-row INSERT historically does not quote its field list; cache TTL persists across query resets; and no PHPStan or PSR-12 script is configured.

## Alternatives

| Alternative | Coupling/cohesion | Compatibility and optionality | Safety/testability | Decision |
|---|---|---|---|---|
| Direct core methods only | High pgvector coupling, easy discovery | Small API but burdens all drivers | Can be safe, poor reuse | High-level convenience only |
| Internal PostgreSQL module | High cohesion | Optional, no package split cost | Strong domain boundaries | Selected for pgvector types |
| Separate package | Lowest core coupling | Best optionality, highest current release cost | Strong | Revisit after API stabilizes |
| Restricted generic expressions | Moderate core change, broadly reusable | Additive | Strong if implementations are trusted | Selected as foundation |
| Plugin/decorator | Low core impact | Optional | Awkward state/filter integration and IDE discovery | Rejected for MVP |

## Decision

Use a small restricted compilation protocol in the core and place vector-specific value, metric, and distance classes under `PostgreSQL/PgVector`. `SqlExpressionInterface` is implemented by library-authored expressions and receives a context that alone can quote identifiers and allocate bindings. It does not accept raw SQL. `DatabaseValueInterface` centralizes serialization, PDO type, safe SQL type, driver support, and canonical cache identity. `nearestNeighbors()` is a discoverable convenience over the explicit expression API.

The metric remains a dedicated enum and is never accepted as a free string or added to `SqlOperator`. The same distance object reuses one placeholder across SELECT and ORDER BY. Identifiers and aliases pass through driver-aware quoting. Values use `CAST()` to avoid named-placeholder ambiguity with PostgreSQL `::` syntax.

Risks are the incremental core surface and the existing mutable builder design. The compilation context is reset with query state, and cache keys are reset between queries. Future generic expressions should remain closed, typed objects; exposing a public arbitrary-expression implementation would defeat the security boundary.

## Phase classification

- MVP: dense `vector`, finite canonical values, dimension assertion, four dense metrics, distance projection/order, conventional filters, bound INSERT/UPDATE, debug/EXPLAIN/cache compatibility.
- Phase 2: explicit extension-version probe, cosine-similarity projection, more generic trusted expressions, integration CI.
- Schema/migrations: vector column types, HNSW/IVFFlat DDL, operator classes, concurrent creation, validated index options.
- Advanced: `halfvec`, `sparsevec`, `bit`, Hamming/Jaccard, binary quantization, iterative scans, transaction-local ANN settings, hybrid ranking and RRF.

## Files and regression risk

| Area | Responsibility | Risk |
|---|---|---|
| `SqlCompilationContext`, expression/value interfaces | safe compilation and binding contracts | Medium |
| `PostgreSQL/PgVector/*` | pgvector domain model | Low, isolated |
| `QueryBuilderOperations` | expression selection/order, convenience API, write casts | Medium |
| `QueryBuilder` | typed binding/debug rendering | Medium |
| cache trait | complete SQL and canonical values in keys | Medium; fixes pre-existing omissions |
| tests/docs/compose/benchmark | verification and operations | Low |
