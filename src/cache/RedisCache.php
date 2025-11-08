<?php

declare(strict_types=1);


namespace Omegaalfa\QueryBuilder\cache;

use Redis;
use Omegaalfa\queryBuilder\interfaces\CacheInterface;

class RedisCache implements CacheInterface
{

	private readonly Redis $redis;

	/**
	 * @param  string  $host
	 */
	public function __construct(string $host)
	{
		$this->redis = new Redis();
		$this->redis->connect($host);
	}

	/**
	 * @param  string  $key
	 *
	 * @return bool
	 */
	public function has(string $key): bool
	{
		return $this->redis->exists($key) > 0;
	}

	/**
	 * @param  string  $key
	 *
	 * @return mixed
	 */
	public function get(string $key): mixed
	{
		try {
			return json_decode($this->redis->get($key), true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
		}
	}

	/**
	 * @param  string  $key
	 * @param  mixed   $value
	 * @param  int     $ttl
	 *
	 * @return void
	 */
	public function set(string $key, mixed $value, int $ttl): void
	{
		try {
			$this->redis->setex($key, $ttl, json_encode($value, JSON_THROW_ON_ERROR));
		} catch (\JsonException $e) {
		}
	}

	/**
	 * @param  string  $key
	 *
	 * @return void
	 */
	public function delete(string $key): void
	{
		$this->redis->del($key);
	}
}
