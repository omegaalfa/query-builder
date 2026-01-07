<?php

declare(strict_types=1);

namespace Omegaalfa\QueryBuilder\traits;

use JsonException;
use Omegaalfa\QueryBuilder\interfaces\CacheInterface;
use Omegaalfa\QueryBuilder\QueryResultDTO;


/**
 * Trait QueryBuilderCacheTrait
 *
 * @property array $sql
 * @property array $params
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
            $data = is_iterable($result->data)
                ? iterator_to_array($result->data, false)
                : $result->data;

            $this->cache->set(
                $this->cacheKey,
                [
                    'data' => $data,
                    'count' => $result->count,
                    'pagination' => $result->pagination,
                    'cached_at' => time(),
                    'ttl' => $this->cacheTtl,
                ],
                $this->cacheTtl
            );
        } catch (\Throwable $e) {
            error_log('[QueryBuilderCache] Save failed: ' . $e->getMessage());
        }
    }

    /**
     * @return string
     * @throws JsonException
     */
    private function generateCacheKey(): string
    {
        $sql = implode(' ', $this->sql);

        $sqlHash = hash('xxh128', $sql);

        $paramsHash = hash(
            'xxh128',
            json_encode($this->params, JSON_THROW_ON_ERROR)
        );

        // Evita colisão entre tenants / conexões
        $contextHash = (
            property_exists($this, 'connection') &&
            $this->connection !== null &&
            method_exists($this->connection, 'getDbSettings')
        )
            ? hash(
                'xxh128',
                json_encode(
                    $this->connection->getDbSettings(),
                    JSON_THROW_ON_ERROR
                )
            )
            : ($this->driver ?? 'default');

        return implode(':', [
            $this->cachePrefix,
            $this->table ?? 'raw',
            $contextHash,
            $sqlHash,
            $paramsHash,
        ]);
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
        } catch (\Throwable $e) {
            error_log('[QueryBuilderCache] Retrieval failed: ' . $e->getMessage());
            return null;
        }
    }
}
