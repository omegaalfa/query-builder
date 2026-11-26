<?php

declare(strict_types=1);

namespace Omegaalfa\QueryBuilder\Traits;

use JsonException;
use Omegaalfa\QueryBuilder\Interfaces\CacheInterface;
use Omegaalfa\QueryBuilder\Interfaces\DatabaseValueInterface;
use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\Internal\TransactionalCacheDependencyRegistry;
use Omegaalfa\QueryBuilder\QueryResultDTO;
use Throwable;


/**
 * Trait QueryBuilderCacheTrait
 *
 * @property array<int, mixed> $sql
 * @property array<string, mixed> $params
 * @property CacheInterface|null $cache
 * @property string|null $table
 * @property string|null $driver
 * @property object $connection
 * @property bool $batchInsert
 * @property bool $cacheEligibleRead
 * @property array<int, array<string, mixed>>|null $sqliteBatchRows
 * @property array<int, string> $insertColumns
 * @property bool $conflictActionDefined
 * @property array<int, string> $returningColumns
 * @property array<int, array{alias: string, sql: string}> $ctes
 * @property list<string> $cacheTableDependencies
 * @property bool $cacheDependenciesIdentifiable
 */
trait QueryBuilderCacheTrait
{
    private int $cacheTtl = 0;

    private ?string $cacheKey = null;

    private string $cachePrefix = 'qb';

    /** @var list<string> */
    private array $cacheTags = [];

    private function supportsReliableCacheInvalidation(): bool
    {
        return $this->cache !== null
            && is_callable([$this->cache, 'setWithTags'])
            && is_callable([$this->cache, 'flushTags']);
    }

    /**
     * Cache only operations explicitly constructed as reads by the fluent API.
     * Raw SQL, CTEs, subqueries, and all write operations remain ineligible.
     */
    private function isCacheEligibleForCurrentQuery(): bool
    {
        return $this->cacheEligibleRead
            && $this->sql !== []
            && $this->insertColumns === []
            && !$this->batchInsert
            && !$this->conflictActionDefined
            && $this->returningColumns === []
            && $this->ctes === []
            && $this->cacheDependenciesIdentifiable
            && $this->cacheTableDependencies !== [];
    }

    /**
     * Cache results only when the PDO transaction state can be confirmed inactive.
     * A connection that cannot expose that state safely is treated as transactional.
     */
    private function hasActiveOrUnknownTransaction(bool $bufferedQuery = true): bool
    {
        try {
            return $this->connection->pdo($bufferedQuery)->inTransaction();
        } catch (Throwable) {
            return true;
        }
    }

    protected function resetQueryCacheKey(): void
    {
        $this->cacheKey = null;
    }

    /**
     * Habilita cache para a query atual.
     */
    public function cache(int $ttl = 3600): self
    {
        if ($ttl < 0) {
            throw new \InvalidArgumentException('Cache TTL cannot be negative.');
        }

        if ($ttl === 0) {
            $this->cacheTtl = 0;
            return $this;
        }

        $this->cacheTtl = $ttl;

        return $this;
    }

    private function consumeCacheTtl(): void
    {
        $this->cacheTtl = 0;
    }

    /** @param array<int, string> $tags */
    public function cacheTags(array $tags): self
    {
        $normalized = [];
        foreach ($tags as $tag) {
            $tag = trim($tag);
            if ($tag !== '') {
                $normalized[] = $tag;
            }
        }

        $this->cacheTags = array_values(array_unique($normalized));
        $this->resetQueryCacheKey();

        return $this;
    }

    /** Salva o resultado da query no cache. */
    /** @param list<string> $tags */
    private function saveToCache(QueryResultDTO $result, array $tags = []): void
    {
        if ($this->cacheTtl <= 0 || $this->cache === null) {
            return;
        }

        try {
            $this->cacheKey ??= $this->generateCacheKey();
            $data = iterator_to_array($result->data, false);

            $payload = [
                'data' => $data,
                'count' => $result->count,
                'pagination' => $result->pagination,
                'cached_at' => time(),
                'ttl' => $this->cacheTtl,
            ];
            if ($this->supportsReliableCacheInvalidation()) {
                call_user_func([$this->cache, 'setWithTags'], $this->cacheKey, $payload, $this->cacheTtl, $tags);
            } else {
                $this->cache->set($this->cacheKey, $payload, $this->cacheTtl);
            }
        } catch (Throwable $e) {
            error_log('[QueryBuilderCache] Save failed: ' . $e->getMessage());
        }
    }

    /**
     * @return string
     * @throws JsonException
     */
    private function generateCacheKey(): string
    {
        if (
            $this->driver === 'sqlite'
            && $this->batchInsert
            && $this->sqliteBatchRows !== null
        ) {
            [$sqlHash, $paramsHash] = $this->sqliteBatchCacheHashes();
        } else {
            $sqlHash = hash('xxh128', $this->getQuerySql());
            $paramsHash = hash(
                'xxh128',
                json_encode($this->canonicalCacheParams(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)
            );
        }

        // Evita colis�o entre tenants / conex�es
        $contextHash = $this->cacheConnectionIdentity();

        return implode(':', [
            $this->cachePrefix,
            $this->table ?? 'raw',
            $contextHash,
            $this->paginationRequested ? 'paginate' : 'limit',
            $sqlHash,
            $paramsHash,
            hash('xxh128', implode('|', $this->cacheTagsForCurrentTable())),
        ]);
    }

    /** @return array{string, string} */
    private function sqliteBatchCacheHashes(): array
    {
        $sql = hash_init('xxh128');
        hash_update($sql, implode(' ', $this->sql) . ' ');
        $params = hash_init('xxh128');
        hash_update($params, '{');

        $parameterIndex = 0;
        $rowIndex = 0;
        foreach ($this->sqliteBatchRows ?? [] as $row) {
            if ($rowIndex > 0) {
                hash_update($sql, ', ');
            }
            hash_update($sql, '(');
            foreach ($this->insertColumns as $columnIndex => $column) {
                if ($columnIndex > 0) {
                    hash_update($sql, ', ');
                }
                $name = ':param' . $parameterIndex++;
                $value = $row[$column];
                hash_update($sql, $this->cacheValuePlaceholder($name, $value));

                if ($parameterIndex > 1) {
                    hash_update($params, ',');
                }
                $canonical = $value instanceof DatabaseValueInterface
                    ? [$value::class, $value->sqlType(), hash('sha256', $value->cacheValue())]
                    : $value;
                hash_update($params, json_encode($name, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
                hash_update($params, ':');
                hash_update($params, json_encode($canonical, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
            }
            hash_update($sql, ')');
            $rowIndex++;
        }

        hash_update($params, '}');
        return [hash_final($sql), hash_final($params)];
    }

    private function cacheValuePlaceholder(string $placeholder, mixed $value): string
    {
        if (!$value instanceof DatabaseValueInterface) {
            return $placeholder;
        }
        $type = $value->sqlType();
        return $type === null ? $placeholder : sprintf('CAST(%s AS %s)', $placeholder, $type);
    }

    /** @return list<string> */
    private function cacheTagsForCurrentTable(): array
    {
        return $this->namespacedCacheTags(includeDatabaseTag: true);
    }

    protected function invalidateCacheTags(bool $bufferedQuery = true): void
    {
        if (!$this->supportsReliableCacheInvalidation() || $this->cache === null) {
            return;
        }

        $tags = $this->namespacedCacheTags(includeDatabaseTag: !$this->cacheDependenciesIdentifiable);
        if ($tags === []) {
            return;
        }

        if ($this->connection instanceof PDOConnection) {
            $transactionLevel = $this->connection->getTransactionLevel();
            if ($transactionLevel > 0) {
                TransactionalCacheDependencyRegistry::record(
                    $this->connection,
                    $transactionLevel,
                    $this->cache,
                    $tags,
                );
                return;
            }
        }

        try {
            if ($this->connection->pdo($bufferedQuery)->inTransaction()) {
                // Direct or wrapper-managed PDO transactions have no lifecycle hook here; retain legacy eager invalidation.
                $this->flushCacheTags($tags);
                return;
            }
        } catch (Throwable $exception) {
            error_log('[QueryBuilderCache] Transaction state unavailable; using immediate invalidation: ' . $exception->getMessage());
        }

        $this->flushCacheTags($tags);
    }

    /** @param list<string> $tags */
    private function flushCacheTags(array $tags): void
    {
        if ($this->cache === null) {
            return;
        }

        try {
            if (call_user_func([$this->cache, 'flushTags'], $tags) === false) {
                error_log('[QueryBuilderCache] Invalidation failed: flushTags() returned false.');
            }
        } catch (Throwable $exception) {
            error_log('[QueryBuilderCache] Invalidation failed: ' . $exception->getMessage());
        }
    }

    /** @return list<string> */
    private function namespacedCacheTags(bool $includeDatabaseTag): array
    {
        $namespace = 'qb:' . $this->cacheConnectionIdentity();
        $tags = [];
        foreach ($this->cacheTags as $tag) {
            $tag = trim($tag);
            if ($tag !== '') {
                $tags[] = $namespace . ':custom:' . hash('xxh128', $tag);
            }
        }
        foreach ($this->cacheTableDependencies as $table) {
            $tags[] = $namespace . ':table:' . hash('xxh128', $table);
        }
        if ($includeDatabaseTag) {
            $tags[] = $namespace . ':database';
        }
        return array_values(array_unique($tags));
    }

    private function cacheConnectionIdentity(): string
    {
        if (method_exists($this->connection, 'getDbSettings')) {
            $settings = $this->connection->getDbSettings();
            $database = is_object($settings) && property_exists($settings, 'database')
                ? $settings->database
                : null;
            if ($database !== ':memory:') {
                if (($settings->driver ?? null) === 'sqlite' && is_string($database)) {
                    $database = realpath($database) ?: (str_starts_with($database, DIRECTORY_SEPARATOR)
                        ? $database
                        : getcwd() . DIRECTORY_SEPARATOR . $database);
                }
                $identityData = [
                    'driver' => $settings->driver ?? null,
                    'host' => $settings->host ?? null,
                    'database' => $database,
                    'port' => $settings->port ?? null,
                    'username' => $settings->username ?? null,
                    'options' => $settings->options ?? null,
                    'charset' => $settings->charset ?? null,
                    'collation' => $settings->collation ?? null,
                    'prefix' => $settings->prefix ?? null,
                ];
                return hash('xxh128', json_encode($identityData, JSON_THROW_ON_ERROR));
            }
        }
        static $tokens;
        $tokens ??= new \WeakMap();
        if (!isset($tokens[$this->connection])) {
            $tokens[$this->connection] = bin2hex(random_bytes(16));
        }
        return $tokens[$this->connection];
    }

    /** @return array<string, mixed> */
    private function canonicalCacheParams(): array
    {
        return array_map(
            static fn (mixed $value): mixed => $value instanceof DatabaseValueInterface
                ? [$value::class, $value->sqlType(), hash("sha256", $value->cacheValue())]
                : $value,
            $this->currentQueryParams(),
        );
    }
    /**
     * Recupera o resultado do cache, se existir.
     */
    private function getFromCache(): ?QueryResultDTO
    {
        if (
            !$this->isCacheEligibleForCurrentQuery() ||
            $this->cacheTtl <= 0 ||
            $this->cache === null
        ) {
            return null;
        }

        try {
            $this->cacheKey ??= $this->generateCacheKey();

            if (!$this->cache->has($this->cacheKey)) {
                return null;
            }

            $cached = $this->cache->get($this->cacheKey);

            if (!is_array($cached) || !array_key_exists('data', $cached)) {
                return null;
            }

            return new QueryResultDTO(
                data: $cached['data'],
                count: $cached['count'] ?? count($cached['data']),
                pagination: $cached['pagination'] ?? null
            );
        } catch (Throwable $e) {
            error_log('[QueryBuilderCache] Retrieval failed: ' . $e->getMessage());
            return null;
        }
    }
}
