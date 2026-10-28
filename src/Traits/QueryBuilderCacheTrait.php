<?php

declare(strict_types=1);

namespace Omegaalfa\QueryBuilder\Traits;

use JsonException;
use Omegaalfa\QueryBuilder\Interfaces\CacheInterface;
use Omegaalfa\QueryBuilder\Interfaces\DatabaseValueInterface;
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
 * @property object|null $connection
 */
trait QueryBuilderCacheTrait
{
    private int $cacheTtl = 0;

    private ?string $cacheKey = null;

    private string $cachePrefix = 'qb';

    /** @var list<string> */
    private array $cacheTags = [];

    protected function resetQueryCacheKey(): void
    {
        $this->cacheKey = null;
    }

    /**
     * Habilita cache para a query atual.
     */
    public function cache(int $ttl = 3600): self
    {
        if ($ttl <= 0) {
            return $this;
        }

        $this->cacheTtl = $ttl;

        return $this;
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

    /**
     * Salva o resultado da query no cache.
     */
    private function saveToCache(QueryResultDTO $result): void
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
            $tags = $this->cacheTagsForCurrentTable();

            if (method_exists($this->cache, 'setWithTags')) {
                $this->cache->setWithTags($this->cacheKey, $payload, $this->cacheTtl, $tags);
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
        $sql = $this->getQuerySql();

        $sqlHash = hash('xxh128', $sql);

        $paramsHash = hash(
            'xxh128',
            json_encode($this->canonicalCacheParams(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)
        );

        // Evita colis�o entre tenants / conex�es
        $contextHash = method_exists($this->connection, 'getDbSettings')
            ? hash(
                'xxh128',
                json_encode(
                    $this->connection->getDbSettings(),
                    JSON_THROW_ON_ERROR
                )
            )
            : $this->driver;

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

    /** @return list<string> */
    private function cacheTagsForCurrentTable(): array
    {
        $tags = $this->cacheTags;
        if ($this->table !== null) {
            $table = trim($this->table, '`"');
            if ($table !== '') {
                $tags[] = $table;
            }
        }

        return array_values(array_unique($tags));
    }

    protected function invalidateCacheTags(): void
    {
        if ($this->cache === null || !method_exists($this->cache, 'flushTags')) {
            return;
        }

        $tags = $this->cacheTagsForCurrentTable();
        if ($tags !== []) {
            try {
                $this->cache->flushTags($tags);
            } catch (Throwable $e) {
                error_log('[QueryBuilderCache] Invalidation failed: ' . $e->getMessage());
            }
        }
    }

    /** @return array<string, mixed> */
    private function canonicalCacheParams(): array
    {
        return array_map(
            static fn (mixed $value): mixed => $value instanceof DatabaseValueInterface
                ? [$value::class, $value->sqlType(), hash("sha256", $value->cacheValue())]
                : $value,
            $this->params,
        );
    }
    /**
     * Recupera o resultado do cache, se existir.
     */
    private function getFromCache(): ?QueryResultDTO
    {
        if (
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
