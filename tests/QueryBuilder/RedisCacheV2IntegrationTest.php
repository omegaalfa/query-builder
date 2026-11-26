<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\Cache\RedisCache;
use Omegaalfa\QueryBuilder\Exceptions\CacheException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Redis;
use RedisException;

final class RedisCacheV2IntegrationTest extends TestCase
{
    private Redis $redis;

    private string $prefix;

    private RedisCache $cache;

    protected function setUp(): void
    {
        if (!class_exists(Redis::class)) {
            self::markTestSkipped('ext-redis is not installed.');
        }

        $this->prefix = 'v2:' . bin2hex(random_bytes(8)) . ':';
        $this->redis = new Redis();
        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int)(getenv('REDIS_PORT') ?: 6379);

        try {
            $this->redis->connect($host, $port, 2.5);
            $this->cache = new RedisCache($host, $this->prefix, $port);
        } catch (RedisException|CacheException $exception) {
            self::markTestSkipped('Redis service is unavailable: ' . $exception->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->cache)) {
            $this->cache->clear();
        }
        if (isset($this->redis)) {
            $this->redis->close();
        }
    }

    public function testNamespacesSeparateEqualDifferentAndOverlappingPrefixes(): void
    {
        $same = new RedisCache($this->host(), $this->prefix, $this->port());
        $different = new RedisCache($this->host(), $this->prefix . 'other:', $this->port());
        $overlapping = new RedisCache($this->host(), substr($this->prefix, 0, -1), $this->port());

        $this->cache->set('item', 'first', 60);
        $same->set('shared', 'same namespace', 60);
        $different->set('item', 'different namespace', 60);
        $overlapping->set('item', 'overlapping namespace', 60);

        self::assertSame('first', $same->get('item'));
        self::assertSame('different namespace', $different->get('item'));
        self::assertSame('overlapping namespace', $overlapping->get('item'));
        self::assertSame(64, strlen($this->namespaceHash($this->prefix)));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $this->namespaceHash($this->prefix));

        $different->clear();
        self::assertSame('first', $this->cache->get('item'));
        self::assertSame('overlapping namespace', $overlapping->get('item'));
        $overlapping->clear();
        $same->clear();
    }

    public function testSpecialPrefixCharactersDoNotAffectPhysicalNamespace(): void
    {
        $specialPrefix = 'odd*?[x]\\:prefix';
        $cache = new RedisCache($this->host(), $specialPrefix, $this->port());
        $cache->set('item', 'value', 60, ['tag*']);

        self::assertSame('value', $cache->get('item'));
        self::assertTrue($cache->clear());
        self::assertFalse($cache->has('item'));
    }

    public function testValuesLocksAndTagIndexesUseSeparateTypes(): void
    {
        $logicalKey = 'item:lock';
        $this->cache->set($logicalKey, 'value', 60, ['tag:one']);
        $valueKey = $this->namespaceRoot() . 'value:' . $logicalKey;
        $keyTagsKey = $this->namespaceRoot() . 'key-tags:' . $logicalKey;
        $tagKey = $this->namespaceRoot() . 'tag:tag:one';

        self::assertSame('value', $this->cache->get($logicalKey));
        self::assertSame(['tag:one'], $this->redis->sMembers($keyTagsKey));
        self::assertSame([$valueKey], $this->redis->sMembers($tagKey));
        self::assertSame('"value"', $this->redis->get($valueKey));
        self::assertFalse((bool)$this->redis->exists($this->namespaceRoot() . 'lock:remember:' . $logicalKey));

        $this->cache->remember('remembered', 60, function (): string {
            self::assertTrue((bool)$this->redis->exists($this->namespaceRoot() . 'lock:remember:remembered'));
            return 'remembered';
        });
        $this->cache->rememberStale('stale', 60, 60, function (): string {
            self::assertTrue((bool)$this->redis->exists($this->namespaceRoot() . 'lock:swr:stale'));
            return 'stale';
        });
    }

    public function testClearPreservesExternalAndOtherNamespaceKeys(): void
    {
        $other = new RedisCache($this->host(), $this->prefix . 'worker:', $this->port());
        $externalKey = 'external:' . bin2hex(random_bytes(8));
        $this->cache->set('owned', 'value', 60, ['owned-tag']);
        $other->set('owned', 'other', 60);
        $this->redis->set($externalKey, 'external');

        self::assertTrue($this->cache->clear());
        self::assertFalse($this->cache->has('owned'));
        self::assertSame('other', $other->get('owned'));
        self::assertSame('external', $this->redis->get($externalKey));
        self::assertSame([], $this->keys($this->namespaceRoot() . '*'));

        $other->clear();
        $this->redis->del($externalKey);
    }

    public function testClearSucceedsWhenNamespaceHasNoKeys(): void
    {
        self::assertSame([], $this->keys($this->namespaceRoot() . '*'));
        self::assertTrue($this->cache->clear());
    }

    public function testClearRejectsEmptyPrefixAndLeavesDatabaseUntouched(): void
    {
        $empty = new RedisCache($this->host(), '', $this->port());
        $externalKey = 'external:' . bin2hex(random_bytes(8));
        $this->redis->set($externalKey, 'keep');

        try {
            $this->expectException(CacheException::class);
            $empty->clear();
        } finally {
            self::assertSame('keep', $this->redis->get($externalKey));
            $this->redis->del($externalKey);
        }
    }

    public function testDeletePatternOnlyMatchesLogicalValueKeys(): void
    {
        $this->cache->set('users:1', 'one', 60, ['users']);
        $this->cache->set('users:2', 'two', 60);
        $this->cache->set('teams:1', 'team', 60);
        $keyTagsKey = $this->namespaceRoot() . 'key-tags:users:1';
        $tagKey = $this->namespaceRoot() . 'tag:users';

        self::assertTrue($this->cache->deletePattern('users:*'));
        self::assertFalse($this->cache->has('users:1'));
        self::assertFalse($this->cache->has('users:2'));
        self::assertSame('team', $this->cache->get('teams:1'));
        self::assertTrue((bool)$this->redis->exists($keyTagsKey));
        self::assertTrue((bool)$this->redis->exists($tagKey));
    }

    public function testCacheDoesNotReadOrDeleteLegacyKeys(): void
    {
        $legacyKey = $this->prefix . 'legacy-item';
        $this->redis->set($legacyKey, '"legacy"');

        self::assertNull($this->cache->get('legacy-item'));
        $this->cache->set('new-item', 'new', 60);
        self::assertTrue($this->cache->clear());
        self::assertSame('"legacy"', $this->redis->get($legacyKey));

        $this->redis->del($legacyKey);
    }

    public function testClearScansAndDeletesThousandsOfNamespaceKeys(): void
    {
        for ($index = 0; $index < 1_200; ++$index) {
            $this->cache->set('bulk:' . $index, $index, 120);
        }

        self::assertTrue($this->cache->clear());
        self::assertSame([], $this->keys($this->namespaceRoot() . '*'));
    }

    public function testImplementationDoesNotUseGlobalOrBlockingDeletionCommands(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Cache/RedisCache.php');
        self::assertIsString($source);
        self::assertStringNotContainsString('->flushDB(', $source);
        self::assertStringNotContainsString('->keys(', $source);
        self::assertStringContainsString('->scan(', $source);
    }

    public function testScanFailureIsWrappedAsCacheException(): void
    {
        $cache = $this->cacheWithRedisMock(function (Redis $redis): void {
            $redis->expects($this->once())->method('scan')
                ->willThrowException(new RedisException('scan failed'));
        });

        $this->expectException(CacheException::class);
        $cache->clear();
    }

    public function testDeleteFailureIsWrappedAndUnsupportedUnlinkFallsBackToDel(): void
    {
        $failed = $this->cacheWithRedisMock(function (Redis $redis): void {
            $redis->expects($this->once())->method('scan')
                ->willReturnCallback(static function (?int &$iterator): array {
                    $iterator = 0;
                    return ['qb:v2:{hash}:value:item'];
                });
            $redis->expects($this->once())->method('unlink')
                ->willThrowException(new RedisException('connection lost'));
            $redis->expects($this->never())->method('del');
        });
        try {
            $failed->clear();
            self::fail('A deletion failure must not be reported as success.');
        } catch (CacheException) {
            self::assertTrue(true);
        }

        $fallback = $this->cacheWithRedisMock(function (Redis $redis): void {
            $redis->expects($this->once())->method('scan')
                ->willReturnCallback(static function (?int &$iterator): array {
                    $iterator = 0;
                    return ['qb:v2:{hash}:value:item'];
                });
            $redis->expects($this->once())->method('unlink')
                ->willThrowException(new RedisException("ERR unknown command 'UNLINK'"));
            $redis->expects($this->once())->method('del')->willReturn(1);
        });

        self::assertTrue($fallback->clear());
    }

    private function cacheWithRedisMock(\Closure $configure): RedisCache
    {
        $redis = $this->createMock(Redis::class);
        $configure($redis);
        $reflection = new ReflectionClass(RedisCache::class);
        $cache = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('redis')->setValue($cache, $redis);
        $reflection->getProperty('prefix')->setValue($cache, 'mock:');
        $reflection->getProperty('namespaceRoot')->setValue($cache, 'qb:v2:{' . hash('sha256', 'mock:') . '}:');

        return $cache;
    }

    /** @return list<string> */
    private function keys(string $pattern): array
    {
        $iterator = null;
        $keys = [];
        do {
            $batch = $this->redis->scan($iterator, $pattern, 500);
            self::assertNotFalse($batch, 'Redis SCAN failed while inspecting integration data.');
            array_push($keys, ...$batch);
        } while ($iterator !== 0 && $iterator !== '0');

        return $keys;
    }

    private function namespaceRoot(): string
    {
        return 'qb:v2:{' . $this->namespaceHash($this->prefix) . '}:';
    }

    private function namespaceHash(string $prefix): string
    {
        return hash('sha256', $prefix);
    }

    private function host(): string
    {
        return getenv('REDIS_HOST') ?: '127.0.0.1';
    }

    private function port(): int
    {
        return (int)(getenv('REDIS_PORT') ?: 6379);
    }
}