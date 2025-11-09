<?php

declare(strict_types=1);


namespace Omegaalfa\QueryBuilder\interfaces;

interface CacheInterface
{
    /**
     * @param string $key
     *
     * @return bool
     */
    public function has(string $key): bool;

    /**
     * @param string $key
     *
     * @return mixed
     */
    public function get(string $key): mixed;

    /**
     * @param string $key
     * @param mixed $value
     * @param int $ttl
     *
     * @return void
     */
    public function set(string $key, mixed $value, int $ttl): void;

    /**
     * @param string $key
     *
     * @return void
     */
    public function delete(string $key): void;

    /**
     * @param string $pattern
     * @return bool
     */
    public function deletePattern(string $pattern): bool;

    /**
     * @return bool
     */
    public function clear(): bool;

    /**
     * @param array $keys
     * @return array
     */
    public function getMultiple(array $keys): array;
}
