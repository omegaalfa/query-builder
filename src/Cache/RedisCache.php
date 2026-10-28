<?php

declare(strict_types=1);


namespace Omegaalfa\QueryBuilder\Cache;

use Closure;
use JsonException;
use Omegaalfa\QueryBuilder\Exceptions\CacheException;
use Omegaalfa\QueryBuilder\Interfaces\CacheInterface;
use Redis;
use RedisException;


class RedisCache implements CacheInterface
{

    private const int LOCK_WAIT_ATTEMPTS = 5;

    private const int LOCK_WAIT_MICROSECONDS = 10_000;

    private const string RELEASE_LOCK_SCRIPT = <<<'LUA'
        if redis.call('get', KEYS[1]) == ARGV[1] then
            return redis.call('del', KEYS[1])
        end
        return 0
        LUA;

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

    /** @param array<int, string> $tags @return list<string> */
    private function normalizeTags(array $tags): array
    {
        $normalized = [];
        foreach ($tags as $tag) {
            $tag = trim($tag);
            if ($tag !== '') {
                $normalized[] = $tag;
            }
        }

        return array_values(array_unique($normalized));
    }

    /** @param list<string> $tags */
    private function replaceTagAssociations(string $key, array $tags): void
    {
        $cacheKey = $this->prefixKey($key);
        $keyTagsKey = $this->prefix . 'key-tags:' . $key;
        $oldTags = $this->redis->sMembers($keyTagsKey);

        if (is_array($oldTags)) {
            foreach ($oldTags as $oldTag) {
                $this->redis->sRem($this->prefix . 'tag:' . $oldTag, $cacheKey);
            }
        }
        $this->redis->del($keyTagsKey);

        foreach ($tags as $tag) {
            if (!$this->redis->sAdd($this->prefix . 'tag:' . $tag, $cacheKey)
                || !$this->redis->sAdd($keyTagsKey, $tag)) {
                throw new CacheException("Failed to index cache tag", $tag);
            }
        }
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param int $ttl
     *
     * @return void
     */
    /** @param array<int, string> $tags */
    public function set(string $key, mixed $value, int $ttl, array $tags = []): void
    {
        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR);

            if (!$this->redis->setex($this->prefixKey($key), $ttl, $encoded)) {
                throw new CacheException("Failed to set cache value", $key);
            }

            $this->replaceTagAssociations($key, $this->normalizeTags($tags));
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
            $this->replaceTagAssociations($key, []);
            $this->redis->del($this->prefixKey($key));
        } catch (RedisException $e) {
            throw new CacheException("Redis error deleting key", $key, $e);
        }
    }

    /** @param array<int, string> $tags */
    public function flushTags(array $tags): bool
    {
        try {
            foreach ($this->normalizeTags($tags) as $tag) {
                $tagKey = $this->prefix . 'tag:' . $tag;
                $keys = $this->redis->sMembers($tagKey);
                if (is_array($keys) && $keys !== []) {
                    foreach ($keys as $cacheKey) {
                        $key = substr($cacheKey, strlen($this->prefix));
                        $this->replaceTagAssociations($key, []);
                    }
                    $this->redis->del(...$keys);
                }
                $this->redis->del($tagKey);
            }

            return true;
        } catch (RedisException $e) {
            throw new CacheException('Redis error flushing cache tags', implode(',', $tags), $e);
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
        try {
            return $this->redis->flushDB();
        } catch (RedisException $e) {
            throw new CacheException('Redis error clearing database.', previous: $e);
        }
    }

    /**
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    public function getMultiple(array $keys): array
    {
        $result = [];
        if ($keys === []) {
            return $result;
        }

        try {
            $prefixedKeys = array_map(fn(string $key): string => $this->prefixKey($key), $keys);
            $values = $this->redis->mGet($prefixedKeys);

            foreach ($keys as $index => $key) {
                $encoded = $values[$index] ?? false;
                if ($encoded === false) {
                    continue;
                }

                try {
                    $result[$key] = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    throw new CacheException('Failed to decode cached value', $key, $e);
                }
            }
        } catch (RedisException $e) {
            throw new CacheException('Redis error getting multiple keys', implode(',', $keys), $e);
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

    /**
     * Recupera um valor do cache ou calcula e salva o resultado uma única vez.
     *
     * Um lock curto por chave reduz o cache stampede quando uma entrada expira.
     * Se outro processo possuir o lock, aguarda uma pequena janela pelo valor
     * preenchido e usa o resolver como fallback caso ele não apareça.
     *
     * @param Closure(): mixed $resolver
     */
    public function remember(string $key, int $ttl, Closure $resolver): mixed
    {
        if ($ttl <= 0) {
            throw new CacheException('Redis cache TTL must be greater than zero.', $key);
        }

        $cached = $this->get($key);
        if ($cached !== null) {
            return $cached;
        }

        $lockKey = $this->prefixKey($key . ':lock');
        $token = bin2hex(random_bytes(16));

        try {
            $acquired = $this->redis->set(
                $lockKey,
                $token,
                ['nx', 'ex' => min(max($ttl, 1), 30)],
            );

            if (!$acquired) {
                for ($attempt = 0; $attempt < self::LOCK_WAIT_ATTEMPTS; ++$attempt) {
                    usleep(self::LOCK_WAIT_MICROSECONDS);
                    $cached = $this->get($key);
                    if ($cached !== null) {
                        return $cached;
                    }
                }

                return $resolver();
            }

            $value = $resolver();
            $this->set($key, $value, $ttl);

            return $value;
        } catch (RedisException $e) {
            throw new CacheException('Redis error acquiring cache lock', $key, $e);
        } finally {
            if (isset($acquired) && $acquired) {
                $this->releaseLock($lockKey, $token);
            }
        }
    }

    private function releaseLock(string $lockKey, string $token): void
    {
        try {
            $this->redis->eval(self::RELEASE_LOCK_SCRIPT, [$lockKey, $token], 1);
        } catch (RedisException) {
            // O lock expira automaticamente; falha na limpeza não invalida o resultado.
        }
    }
}
