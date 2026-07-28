<?php

declare(strict_types=1);


namespace Omegaalfa\QueryBuilder\Cache;

use JsonException;
use Omegaalfa\QueryBuilder\Exceptions\CacheException;
use Omegaalfa\QueryBuilder\Interfaces\CacheInterface;
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
     * @param int $port
     * @param string|null $password
     * @param float $timeout
     * @param int $database
     */
    public function __construct(
        string $host,
        string $prefix = 'qb:',
        int $port = 6379,
        ?string $password = null,
        float $timeout = 2.5,
        int $database = 0,
    ) {
        if ($port < 1 || $port > 65535) {
            throw new CacheException('Redis port must be between 1 and 65535.');
        }
        if ($timeout <= 0) {
            throw new CacheException('Redis timeout must be greater than zero.');
        }
        if ($database < 0) {
            throw new CacheException('Redis database must be zero or greater.');
        }

        $this->redis = new Redis();
        $this->prefix = $prefix;
        try {
            if (!$this->redis->connect($host, $port, $timeout)) {
                throw new CacheException("Cannot connect to Redis at {$host}:{$port}.");
            }
            if ($password !== null && $password !== '' && !$this->redis->auth($password)) {
                throw new CacheException('Redis authentication failed.');
            }
            if ($database !== 0 && !$this->redis->select($database)) {
                throw new CacheException("Unable to select Redis database {$database}.");
            }
        } catch (RedisException $e) {
            throw new CacheException('Unable to initialize Redis connection.', previous: $e);
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
        } catch (RedisException $e) {
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
        } catch (JsonException $e) {
            throw new CacheException("Failed to decode cached value", $key, $e);
        } catch (RedisException $e) {
            throw new CacheException("Redis error getting key", $key, $e);
        }
    }
}
