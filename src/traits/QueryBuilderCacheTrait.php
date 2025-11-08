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
     * @param QueryResultDTO $result
     *
     * @return void
     */
    private function saveToCache(QueryResultDTO $result): void
    {
        if (!isset($this->cacheTtl, $this->cacheKey) || is_null($this->cache)) {
            return;
        }

        $this->cache->set($this->cacheKey, $result, $this->cacheTtl);
    }

    /**
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
            return new QueryResultDTO(
                data: $cachedResult['data'],
                count: $cachedResult['count'],
                pagination: $cachedResult['pagination']
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
