<?php

declare(strict_types=1);


namespace Omegaalfa\QueryBuilder\traits;

use Omegaalfa\QueryBuilder\QueryBuilder;
use Omegaalfa\QueryBuilder\QueryResultDTO;

trait QueryBuilderCacheTrait
{
    /**
     * @var int
     */
    private int $cacheTtl;

    /**
     * @var string
     */
    private string $cacheKey;

    /**
     * @var string
     */
    private string $cachePrefix = 'qb';


    /**
     * @param int $ttl
     *
     * @return QueryBuilderCacheTrait|QueryBuilder
     */
    public function cache(int $ttl = 3600): self
    {
        $this->cacheTtl = $ttl;
        return $this;
    }

    /**
     * Salva o resultado da query no cache.
     *
     * @param QueryResultDTO $result
     * @return void
     */
    private function saveToCache(QueryResultDTO $result): void
    {
        if (!isset($this->cacheTtl, $this->cacheKey) || is_null($this->cache)) {
            return;
        }

        try {
            $data = is_iterable($result->data)
                ? iterator_to_array($result->data, false)
                : $result->data;

            $cachedPayload = [
                'data' => $data,
                'count' => $result->count,
                'pagination' => $result->pagination,
                'cached_at' => time(),
                'ttl' => $this->cacheTtl
            ];

            $this->cache->set($this->cacheKey, $cachedPayload, $this->cacheTtl);
        } catch (\Throwable $e) {
            error_log("Cache save failed: " . $e->getMessage());
        }
    }

    /**
     * Recupera o resultado do cache, se existir.
     *
     * @return QueryResultDTO|null
     */
    private function getFromCache(): ?QueryResultDTO
    {
        if (!isset($this->cacheTtl) || is_null($this->cache)) {
            return null;
        }

        $this->cacheKey = $this->generateCacheKey();

        try {
            if ($this->cache->has($this->cacheKey)) {
                $cachedResult = $this->cache->get($this->cacheKey);

                if (!is_array($cachedResult) || !isset($cachedResult['data'])) {
                    return null;
                }

                return new QueryResultDTO(
                    data: $cachedResult['data'],
                    count: $cachedResult['count'] ?? count($cachedResult['data']),
                    pagination: $cachedResult['pagination'] ?? null
                );
            }
        } catch (\Throwable $e) {
            error_log("Cache retrieval failed: " . $e->getMessage());
        }

        return null;
    }

    /**
     * @return string
     */
    private function generateCacheKey(): string
    {
        $sql = implode(' ', $this->sql);
        $paramsHash = md5(serialize($this->params));
        $sqlHash = md5($sql);

        // Formato: prefix:table:sqlhash:paramshash
        $parts = [
            $this->cachePrefix,
            $this->table ?? 'raw',
            $sqlHash,
            $paramsHash
        ];

        return implode(':', $parts);
    }
}
