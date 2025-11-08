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

        $data = is_iterable($result->data)
            ? iterator_to_array($result->data, false)
            : $result->data;

        $cachedPayload = [
            'data' => $data,
            'count' => $result->count,
            'pagination' => $result->pagination,
        ];

        $this->cache->set($this->cacheKey, $cachedPayload, $this->cacheTtl);
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

        if ($this->cache->has($this->cacheKey)) {
            $cachedResult = $this->cache->get($this->cacheKey);

            if (!is_array($cachedResult) || !isset($cachedResult['data'])) {
                return null; // evita erros se o cache estiver corrompido
            }

            return new QueryResultDTO(
                data: $cachedResult['data'],
                count: $cachedResult['count'] ?? count($cachedResult['data']),
                pagination: $cachedResult['pagination'] ?? null
            );
        }

        return null;
    }

    /**
     * @return string
     */
    private function generateCacheKey(): string
    {
        $sql = implode(' ', $this->sql);
        return md5($sql . serialize($this->params));
    }
}
