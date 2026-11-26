<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\Cache\RedisCache;
use Omegaalfa\QueryBuilder\Exceptions\CacheException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Redis;
use RedisException;

final class RedisCacheTagCommandResultTest extends TestCase
{
    public function testSAddZeroIsAcceptedAsExistingSetMembership(): void
    {
        $redis = $this->redisMock();
        $redis->expects(self::once())->method('setex')->willReturn(true);
        $redis->expects(self::once())->method('sMembers')->willReturn([]);
        $redis->expects(self::once())->method('del')->willReturn(0);
        $redis->expects(self::exactly(2))->method('sAdd')->willReturnOnConsecutiveCalls(0, 1);

        $this->cacheWithRedis($redis)->set('item', 'value', 60, ['tag']);
        self::assertTrue(true);
    }

    public function testRealRedisSAddZeroDoesNotFailTagIndexing(): void
    {
        if (!class_exists(Redis::class)) {
            self::markTestSkipped('ext-redis is not installed.');
        }

        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int)(getenv('REDIS_PORT') ?: 6379);
        $prefix = 'sadd-zero:' . bin2hex(random_bytes(8)) . ':';

        try {
            $cache = new RedisCache($host, $prefix, $port);
            $redis = new Redis();
            $redis->connect($host, $port);
        } catch (CacheException|RedisException $exception) {
            self::markTestSkipped('Redis service is unavailable: ' . $exception->getMessage());
        }

        $root = 'qb:v2:{' . hash('sha256', $prefix) . '}:';
        $valueKey = $root . 'value:item';
        $tagKey = $root . 'tag:tag';
        $redis->sAdd($tagKey, $valueKey);

        try {
            $cache->set('item', 'value', 60, ['tag']);
            self::assertSame('value', $cache->get('item'));
            self::assertSame(['tag'], $redis->sMembers($root . 'key-tags:item'));
        } finally {
            $cache->clear();
            $redis->close();
        }
    }

    public function testFalseFromTagIndexCommandsBecomesCacheException(): void
    {
        $cases = [
            'read inverse index' => function (Redis $redis): void {
                $redis->expects($this->once())->method('setex')->willReturn(true);
                $redis->expects($this->once())->method('sMembers')->willReturn(false);
            },
            'remove old tag association' => function (Redis $redis): void {
                $redis->expects($this->once())->method('setex')->willReturn(true);
                $redis->expects($this->once())->method('sMembers')->willReturn(['old']);
                $redis->expects($this->once())->method('sRem')->willReturn(false);
            },
            'delete inverse index' => function (Redis $redis): void {
                $redis->expects($this->once())->method('setex')->willReturn(true);
                $redis->expects($this->once())->method('sMembers')->willReturn([]);
                $redis->expects($this->once())->method('del')->willReturn(false);
            },
            'add tag association' => function (Redis $redis): void {
                $redis->expects($this->once())->method('setex')->willReturn(true);
                $redis->expects($this->once())->method('sMembers')->willReturn([]);
                $redis->expects($this->once())->method('del')->willReturn(0);
                $redis->expects($this->once())->method('sAdd')->willReturn(false);
            },
        ];

        foreach ($cases as $case => $configure) {
            $redis = $this->redisMock();
            $configure($redis);
            $cache = $this->cacheWithRedis($redis);

            try {
                $cache->set('item', 'value', 60, ['tag']);
                self::fail("A false Redis result for {$case} must not be reported as success.");
            } catch (CacheException $exception) {
                self::assertStringContainsString('Redis error setting key', $exception->getMessage(), $case);
            }
        }
    }

    public function testFalseFromDeleteValueCommandBecomesCacheException(): void
    {
        $redis = $this->redisMock();
        $redis->expects(self::once())->method('sMembers')->willReturn([]);
        $redis->expects(self::exactly(2))->method('del')->willReturnOnConsecutiveCalls(0, false);
        $cache = $this->cacheWithRedis($redis);

        try {
            $cache->delete('item');
            self::fail('A false value deletion result must not be ignored.');
        } catch (CacheException $exception) {
            self::assertStringContainsString('Redis error deleting key', $exception->getMessage());
        }
    }

    public function testFalseFromFlushTagCommandsBecomesCacheException(): void
    {
        $cases = [
            'read tag index' => function (Redis $redis): void {
                $redis->expects($this->once())->method('sMembers')->willReturn(false);
            },
            'delete tagged values' => function (Redis $redis): void {
                $redis->expects(self::exactly(2))->method('sMembers')
                    ->willReturnOnConsecutiveCalls([$this->namespaceRoot() . 'value:item'], []);
                $redis->expects($this->exactly(2))->method('del')->willReturnOnConsecutiveCalls(0, false);
            },
            'delete tag index' => function (Redis $redis): void {
                $redis->expects($this->once())->method('sMembers')->willReturn([]);
                $redis->expects($this->once())->method('del')->willReturn(false);
            },
        ];

        foreach ($cases as $case => $configure) {
            $redis = $this->redisMock();
            $configure($redis);
            $cache = $this->cacheWithRedis($redis);

            try {
                $cache->flushTags(['tag']);
                self::fail("A false Redis result for {$case} must not be reported as success.");
            } catch (CacheException $exception) {
                self::assertStringContainsString('Redis error flushing cache tags', $exception->getMessage(), $case);
            }
        }
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
        $prefix = 'tag-results:';
        $reflection = new ReflectionClass(RedisCache::class);
        $cache = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('redis')->setValue($cache, $redis);
        $reflection->getProperty('prefix')->setValue($cache, $prefix);
        $reflection->getProperty('namespaceRoot')->setValue(
            $cache,
            'qb:v2:{' . hash('sha256', $prefix) . '}:',
        );
        $reflection->getProperty('circuitFailureThreshold')->setValue($cache, 3);
        $reflection->getProperty('circuitRecoverySeconds')->setValue($cache, 30);
        $reflection->getProperty('failOpen')->setValue($cache, false);
        $reflection->getProperty('retryAttempts')->setValue($cache, 0);
        $reflection->getProperty('retryDelayMs')->setValue($cache, 0);
        $reflection->getProperty('retryBackoffMultiplier')->setValue($cache, 2.0);
        $reflection->getProperty('observer')->setValue($cache, null);

        return $cache;
    }

    private function namespaceRoot(): string
    {
        return 'qb:v2:{' . hash('sha256', 'tag-results:') . '}:';
    }
}
