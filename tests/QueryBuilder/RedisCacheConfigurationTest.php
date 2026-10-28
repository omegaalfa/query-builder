<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\Cache\RedisCache;
use Omegaalfa\QueryBuilder\Exceptions\CacheException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Redis;
use RedisException;

final class RedisCacheConfigurationTest extends TestCase
{
    /** @param array<string, mixed> $arguments */
    #[DataProvider('invalidConfiguration')]
    public function testRejectsInvalidConnectionConfiguration(array $arguments): void
    {
        $this->expectException(CacheException::class);
        new RedisCache('127.0.0.1', ...$arguments);
    }

    public static function invalidConfiguration(): iterable
    {
        yield 'port below range' => [['port' => 0]];
        yield 'port above range' => [['port' => 65_536]];
        yield 'non-positive timeout' => [['timeout' => 0.0]];
        yield 'negative database' => [['database' => -1]];
    }

    public function testGetMultiplePreservesFalseZeroAndEmptyStringValues(): void
    {
        $redis = $this->redisMock();
        $redis->expects(self::once())->method('mGet')
            ->with(['test:false', 'test:zero', 'test:empty'])
            ->willReturn([json_encode(false), json_encode(0), json_encode('')]);
        $cache = $this->cacheWithRedis($redis);

        self::assertSame(
            ['false' => false, 'zero' => 0, 'empty' => ''],
            $cache->getMultiple(['false', 'zero', 'empty']),
        );
    }

    public function testGetMultipleOmitsMissingValuesOnly(): void
    {
        $redis = $this->redisMock();
        $redis->expects(self::once())->method('mGet')
            ->with(['test:missing', 'test:present'])
            ->willReturn([false, json_encode(['id' => 1])]);
        $cache = $this->cacheWithRedis($redis);

        self::assertSame(['present' => ['id' => 1]], $cache->getMultiple(['missing', 'present']));
    }

    public function testGetMultipleReturnsEmptyArrayWithoutCallingRedis(): void
    {
        $redis = $this->redisMock();
        $redis->expects(self::never())->method('mGet');
        $cache = $this->cacheWithRedis($redis);

        self::assertSame([], $cache->getMultiple([]));
    }

    public function testGetMultipleWrapsRedisFailures(): void
    {
        $redis = $this->redisMock();
        $redis->expects(self::once())
            ->method('mGet')
            ->willThrowException(new RedisException('connection lost'));
        $cache = $this->cacheWithRedis($redis);

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Redis error getting multiple keys');
        $cache->getMultiple(['item']);
    }

    public function testGetMultipleWrapsInvalidJson(): void
    {
        $redis = $this->redisMock();
        $redis->expects(self::once())->method('mGet')->willReturn(['not-json']);
        $cache = $this->cacheWithRedis($redis);

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Failed to decode cached value');
        $cache->getMultiple(['item']);
    }

    public function testSetUsesPrefixedKeyJsonAndTtl(): void
    {
        $redis = $this->redisMock();
        $redis->expects(self::once())
            ->method('setex')
            ->with('test:item', 30, '{"enabled":true}')
            ->willReturn(true);
        $cache = $this->cacheWithRedis($redis);

        $cache->set('item', ['enabled' => true], 30);
    }

    public function testDeletePatternReturnsTrueWhenNoKeysExist(): void
    {
        $redis = $this->redisMock();
        $redis->expects(self::once())->method('keys')->with('test:*')->willReturn([]);
        $redis->expects(self::never())->method('del');
        $cache = $this->cacheWithRedis($redis);

        self::assertTrue($cache->deletePattern('*'));
    }

    public function testRedisExceptionIsWrappedWhenClearing(): void
    {
        $redis = $this->redisMock();
        $redis->expects(self::once())
            ->method('flushDB')
            ->willThrowException(new RedisException('connection lost'));
        $cache = $this->cacheWithRedis($redis);

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Redis error clearing database.');
        $cache->clear();
    }

    private function redisMock(): Redis
    {
        if (!class_exists(Redis::class)) {
            self::markTestSkipped('ext-redis is not installed.');
        }

        return $this->createMock(Redis::class);
    }

    private function cacheWithRedis(Redis $redis): RedisCache
    {
        $reflection = new ReflectionClass(RedisCache::class);
        $cache = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('redis')->setValue($cache, $redis);
        $reflection->getProperty('prefix')->setValue($cache, 'test:');

        return $cache;
    }
}
