<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\Cache\RedisCache;
use Omegaalfa\QueryBuilder\Exceptions\CacheException;
use PHPUnit\Framework\TestCase;
use Redis;

final class RedisCacheIntegrationTest extends TestCase
{
    private RedisCache $cache;

    private string $prefix;

    protected function setUp(): void
    {
        if (!class_exists(Redis::class)) {
            self::markTestSkipped('ext-redis is not installed.');
        }

        $this->prefix = 'integration:' . bin2hex(random_bytes(8)) . ':';

        try {
            $this->cache = new RedisCache(
                host: getenv('REDIS_HOST') ?: '127.0.0.1',
                port: (int)(getenv('REDIS_PORT') ?: 6379),
                prefix: $this->prefix,
            );
        } catch (CacheException $exception) {
            self::markTestSkipped('Redis service is unavailable: ' . $exception->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->cache)) {
            $this->cache->deletePattern('*');
        }
    }

    public function testStoresReadsAndDeletesValuesFromRedis(): void
    {
        $this->cache->set('item', ['id' => 1, 'active' => true], 30);

        self::assertTrue($this->cache->has('item'));
        self::assertSame(['id' => 1, 'active' => true], $this->cache->get('item'));

        $this->cache->delete('item');
        self::assertFalse($this->cache->has('item'));
        self::assertNull($this->cache->get('item'));
    }

    public function testReadsMultipleValuesWithOneRedisOperationContract(): void
    {
        $this->cache->set('false', false, 30);
        $this->cache->set('zero', 0, 30);
        $this->cache->set('empty', '', 30);

        self::assertSame(
            ['false' => false, 'zero' => 0, 'empty' => ''],
            $this->cache->getMultiple(['false', 'zero', 'empty', 'missing']),
        );
    }

    public function testRememberUsesTheStoredValueAfterFirstResolution(): void
    {
        $calls = 0;
        $resolver = function () use (&$calls): array {
            ++$calls;
            return ['generated' => true];
        };

        self::assertSame(['generated' => true], $this->cache->remember('remembered', 30, $resolver));
        self::assertSame(['generated' => true], $this->cache->remember('remembered', 30, $resolver));
        self::assertSame(1, $calls);
    }

    public function testFlushTagsRemovesAssociatedValues(): void
    {
        $this->cache->set('user:1', ['id' => 1], 30, ['users', 'tenant:10']);
        $this->cache->set('user:2', ['id' => 2], 30, ['users']);

        self::assertTrue($this->cache->flushTags(['users']));
        self::assertNull($this->cache->get('user:1'));
        self::assertNull($this->cache->get('user:2'));
    }

    public function testEmptyAndDuplicateTagsAreIgnored(): void
    {
        $this->cache->set('item', 'value', 30, ['', 'items', 'items', '  ']);

        self::assertTrue($this->cache->flushTags(['items']));
        self::assertNull($this->cache->get('item'));
    }

    public function testReplacingTagsRemovesTheOldAssociation(): void
    {
        $this->cache->set('item', 'value', 30, ['old']);
        $this->cache->set('item', 'value', 30, ['new']);

        self::assertTrue($this->cache->flushTags(['old']));
        self::assertSame('value', $this->cache->get('item'));
        self::assertTrue($this->cache->flushTags(['new']));
        self::assertNull($this->cache->get('item'));
    }

    public function testRememberStaleReturnsFreshValueWithoutResolvingAgain(): void
    {
        $calls = 0;
        $resolver = function () use (&$calls): string {
            ++$calls;
            return 'fresh';
        };

        self::assertSame('fresh', $this->cache->rememberStale('swr', 30, 30, $resolver));
        self::assertSame('fresh', $this->cache->rememberStale('swr', 30, 30, $resolver));
        self::assertSame(1, $calls);
    }

    public function testRememberStaleRefreshesAfterFreshWindow(): void
    {
        $calls = 0;
        $resolver = function () use (&$calls): string {
            ++$calls;
            return 'value-' . $calls;
        };

        self::assertSame('value-1', $this->cache->rememberStale('swr', 1, 3, $resolver));
        sleep(2);
        self::assertSame('value-2', $this->cache->rememberStale('swr', 1, 3, $resolver));
        self::assertSame(2, $calls);
    }

    public function testRememberStaleRejectsInvalidWindows(): void
    {
        $this->expectException(CacheException::class);
        $this->cache->rememberStale('swr', 0, 30, static fn(): string => 'value');
    }

    public function testCacheStatsExposeAndResetUsageCounters(): void
    {
        $this->cache->remember('stats', 30, static fn(): string => 'value');
        $this->cache->remember('stats', 30, static fn(): string => 'unused');

        self::assertSame(1, $this->cache->getStats()['misses']);
        self::assertSame(1, $this->cache->getStats()['hits']);
        self::assertSame(1, $this->cache->getStats()['refreshes']);

        $this->cache->resetStats();
        self::assertSame(
            ['hits' => 0, 'misses' => 0, 'stale_hits' => 0, 'refreshes' => 0, 'lock_contention' => 0],
            $this->cache->getStats(),
        );
    }

    public function testExpirationRemovesValueFromRedis(): void
    {
        $this->cache->set('short-lived', 'value', 1);

        self::assertSame('value', $this->cache->get('short-lived'));
        sleep(2);
        self::assertNull($this->cache->get('short-lived'));
    }
}
