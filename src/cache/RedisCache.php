<?php

declare(strict_types=1);


namespace Omegaalfa\QueryBuilder\cache;

use JsonException;
use Omegaalfa\QueryBuilder\exceptions\CacheException;
use Omegaalfa\QueryBuilder\interfaces\CacheInterface;
use Redis;
use RedisException;


class RedisCache implements CacheInterface
{

    /**
     * @var Redis
     */
    private readonly Redis $redis;

    /**
     * @var string
     */
    private readonly string $prefix;

    /**
     * @param string $host
     * @param string $prefix
     */
    public function __construct(string $host, string $prefix = 'qb:')
    {
        $this->redis = new Redis();
        $this->prefix = $prefix;
        if (!$this->redis->connect($host)) {
            throw new CacheException("Cannot connect to Redis host '{$host}'.");
        }
    }

    /**
     * @param string $key
     *
     * @return bool
     */
    public function has(string $key): bool
    {
        try {
            return $this->redis->exists($this->prefixKey($key)) > 0;
        } catch (\RedisException $e) {
            throw new CacheException("Redis error checking key", $key, $e);
        }
    }

    private function prefixKey(string $key): string
    {
        return $this->prefix . $key;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param int $ttl
     *
     * @return void
     */
    public function set(string $key, mixed $value, int $ttl): void
    {
        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR);

            if (!$this->redis->setex($this->prefixKey($key), $ttl, $encoded)) {
                throw new CacheException("Failed to set cache value", $key);
            }
        } catch (JsonException $e) {
            throw new CacheException("Failed to encode value for cache", $key, $e);
        } catch (RedisException $e) {
            throw new CacheException("Redis error setting key", $key, $e);
        }
    }

    /**
     * @param string $key
     *
     * @return void
     */
    public function delete(string $key): void
    {
        try {
            $this->redis->del($this->prefixKey($key));
        } catch (RedisException $e) {
            throw new CacheException("Redis error deleting key", $key, $e);
        }
    }

    /**
     * @param string $pattern
     * @return bool
     */
    public function deletePattern(string $pattern): bool
    {
        try {
            $keys = $this->redis->keys($this->prefixKey($pattern));
            if (empty($keys)) {
                return true;
            }
            return $this->redis->del(...$keys) > 0;
        } catch (RedisException $e) {
            throw new CacheException("Redis error deleting pattern", $pattern, $e);
        }
    }

    /**
     * @return bool
     */
    public function clear(): bool
    {
        return $this->redis->flushDB();
    }

    /**
     * @param array $keys
     * @return array
     */
    public function getMultiple(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            if ($value = $this->get($key)) {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /**
     * @param string $key
     *
     * @return mixed
     */
    public function get(string $key): mixed
    {
        try {
            $value = $this->redis->get($this->prefixKey($key));

            if ($value === false) {
                return null;
            }

            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new CacheException("Failed to decode cached value", $key, $e);
        } catch (\RedisException $e) {
            throw new CacheException("Redis error getting key", $key, $e);
        }
    }
}
