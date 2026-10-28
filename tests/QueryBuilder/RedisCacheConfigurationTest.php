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
use RuntimeException;

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
        yield 'non-positive circuit threshold' => [['circuitFailureThreshold' => 0]];
        yield 'non-positive circuit recovery window' => [['circuitRecoverySeconds' => 0]];
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

    public function testRememberReturnsCachedValueWithoutResolving(): void
    {
        $redis = $this->redisMock();
        $redis->expects(self::once())->method('get')->with('test:item')->willReturn('{"value":1}');
        $redis->expects(self::never())->method('set');
        $cache = $this->cacheWithRedis($redis);

        self::assertSame(['value' => 1], $cache->remember('item', 30, static function (): never {
            throw new RuntimeException('resolver should not run on cache hit');
        }));
    }

    public function testRememberLocksResolvesStoresAndReleasesTheKey(): void
    {
        $redis = $this->redisMock();
        $token = null;
        $redis->expects(self::once())->method('get')->with('test:item')->willReturn(false);
        $redis->expects(self::once())->method('set')
            ->with(
                'test:item:lock',
                self::isType('string'),
                ['nx', 'ex' => 30],
            )
            ->willReturnCallback(function (string $key, string $value) use (&$token): bool {
                $token = $value;
                return true;
            });
        $redis->expects(self::once())->method('setex')
            ->with('test:item', 30, '{"value":2}')
            ->willReturn(true);
        $redis->expects(self::once())->method('eval')
            ->with(
                self::stringContains("redis.call('get', KEYS[1]) == ARGV[1]"),
                self::callback(static fn(array $arguments): bool => $arguments[0] === 'test:item:lock'
                    && is_string($arguments[1])
                    && strlen($arguments[1]) === 32),
                1,
            )
            ->willReturn(1);
        $cache = $this->cacheWithRedis($redis);

        self::assertSame(['value' => 2], $cache->remember('item', 30, static fn(): array => ['value' => 2]));
    }

    public function testRememberDoesNotDeleteAReplacedLock(): void
    {
        $redis = $this->redisMock();
        $redis->expects(self::once())->method('get')->with('test:item')->willReturn(false);
        $redis->expects(self::once())->method('set')->willReturn(true);
        $redis->expects(self::once())->method('setex')->willReturn(true);
        $redis->expects(self::once())->method('eval')
            ->with(
                self::stringContains('ARGV[1]'),
                self::isType('array'),
                1,
            )
            ->willReturn(0);
        $redis->expects(self::never())->method('del');
        $cache = $this->cacheWithRedis($redis);

        self::assertSame('value', $cache->remember('item', 30, static fn(): string => 'value'));
    }

    public function testRememberWaitsForAnotherProcessToPopulateTheValue(): void
    {
        $redis = $this->redisMock();
        $reads = 0;
        $redis->expects(self::exactly(2))->method('get')
            ->willReturnCallback(function (string $key) use (&$reads): string|false {
                ++$reads;
                return $key === 'test:item' && $reads === 2 ? '"ready"' : false;
            });
        $redis->expects(self::once())->method('set')->willReturn(false);
        $cache = $this->cacheWithRedis($redis);

        self::assertSame('ready', $cache->remember('item', 30, static function (): never {
            throw new RuntimeException('resolver should not run after another process fills cache');
        }));
    }

    public function testRememberRejectsNonPositiveTtl(): void
    {
        $redis = $this->redisMock();
        $redis->expects(self::never())->method('get');
        $cache = $this->cacheWithRedis($redis);

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('TTL must be greater than zero');
        $cache->remember('item', 0, static fn(): string => 'value');
    }

    public function testHealthReportsRedisFailureWithoutThrowing(): void
    {
        $redis = $this->redisMock();
        $redis->expects(self::once())
            ->method('ping')
            ->willThrowException(new RedisException('connection refused'));
        $cache = $this->cacheWithRedis($redis);

        $health = $cache->health();

        self::assertFalse($health['available']);
        self::assertIsFloat($health['latency_ms']);
        self::assertSame('connection refused', $health['error']);
    }

    public function testPingReflectsRedisAvailability(): void
    {
        $redis = $this->redisMock();
        $redis->expects(self::once())->method('ping')->willReturn(true);
        $cache = $this->cacheWithRedis($redis);

        self::assertTrue($cache->ping());
    }

    public function testCircuitOpensAfterConsecutiveRedisFailuresAndCanBeReset(): void
    {
        $redis = $this->redisMock();
        $calls = 0;
        $redis->expects(self::exactly(3))->method('get')
            ->willReturnCallback(function () use (&$calls): string|false {
                ++$calls;
                throw new RedisException('connection lost');
            });
        $cache = $this->cacheWithRedis($redis);

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            try {
                $cache->get('item');
            } catch (CacheException) {
                // Expected while the circuit accumulates failures.
            }
        }

        self::assertSame('open', $cache->circuitState()['state']);
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('circuit breaker is open');
        $cache->get('item');
    }

    public function testResetCircuitAllowsRedisCallsAgain(): void
    {
        $redis = $this->redisMock();
        $redis->expects(self::exactly(3))->method('get')
            ->willThrowException(new RedisException('connection lost'));
        $cache = $this->cacheWithRedis($redis);

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            try {
                $cache->get('item');
            } catch (CacheException) {
                // Expected while the circuit accumulates failures.
            }
        }

        $cache->resetCircuit();
        self::assertSame('closed', $cache->circuitState()['state']);
    }

    public function testRememberUsesResolverWhenFailOpenAndRedisFails(): void
    {
        $redis = $this->redisMock();
        $redis->expects(self::once())
            ->method('get')
            ->willThrowException(new RedisException('connection refused'));
        $cache = $this->cacheWithRedis($redis, failOpen: true);

        self::assertSame('fallback', $cache->remember('item', 30, static fn(): string => 'fallback'));
    }

    public function testRememberStillThrowsWhenFailClosedAndRedisFails(): void
    {
        $redis = $this->redisMock();
        $redis->expects(self::once())
            ->method('get')
            ->willThrowException(new RedisException('connection refused'));
        $cache = $this->cacheWithRedis($redis);

        $this->expectException(CacheException::class);
        $cache->remember('item', 30, static fn(): string => 'unused');
    }

    public function testRememberReturnsResolvedValueWhenCacheWriteFailsInFailOpenMode(): void
    {
        $redis = $this->redisMock();
        $redis->expects(self::once())->method('get')->with('test:item')->willReturn(false);
        $redis->expects(self::once())->method('set')->willReturn(true);
        $redis->expects(self::once())->method('setex')->willThrowException(new RedisException('write failed'));
        $redis->expects(self::once())->method('eval')->willReturn(1);
        $cache = $this->cacheWithRedis($redis, failOpen: true);

        self::assertSame('resolved', $cache->remember('item', 30, static fn(): string => 'resolved'));
        self::assertSame(1, $cache->getStats()['fallbacks']);
    }

    public function testGetMultipleReturnsEmptyArrayWhenFailOpenAndRedisFails(): void
    {
        $redis = $this->redisMock();
        $redis->expects(self::once())->method('mGet')->willThrowException(new RedisException('read failed'));
        $cache = $this->cacheWithRedis($redis, failOpen: true);

        self::assertSame([], $cache->getMultiple(['item']));
        self::assertSame(1, $cache->getStats()['fallbacks']);
    }

    public function testRedisReadRetriesWithBackoffAndEventuallySucceeds(): void
    {
        $redis = $this->redisMock();
        $calls = 0;
        $redis->expects(self::exactly(2))->method('get')
            ->willReturnCallback(function () use (&$calls): string|false {
                ++$calls;
                if ($calls === 1) {
                    throw new RedisException('temporary failure');
                }
                return '"ready"';
            });
        $cache = $this->cacheWithRedis($redis);

        self::assertSame('ready', $cache->get('item'));
        self::assertSame(1, $cache->getStats()['retries']);
    }

    public function testRetryAttemptsAreValidated(): void
    {
        $this->expectException(CacheException::class);
        new RedisCache('127.0.0.1', retryAttempts: -1);
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

    private function cacheWithRedis(Redis $redis, bool $failOpen = false): RedisCache
    {
        $reflection = new ReflectionClass(RedisCache::class);
        $cache = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('redis')->setValue($cache, $redis);
        $reflection->getProperty('prefix')->setValue($cache, 'test:');
        $reflection->getProperty('circuitFailureThreshold')->setValue($cache, 3);
        $reflection->getProperty('circuitRecoverySeconds')->setValue($cache, 30);
        $reflection->getProperty('failOpen')->setValue($cache, $failOpen);
        $reflection->getProperty('retryAttempts')->setValue($cache, 2);
        $reflection->getProperty('retryDelayMs')->setValue($cache, 0);
        $reflection->getProperty('retryBackoffMultiplier')->setValue($cache, 2.0);

        return $cache;
    }
}
