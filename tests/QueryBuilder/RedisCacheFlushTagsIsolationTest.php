<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\Cache\RedisCache;
use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Redis;

final class RedisCacheFlushTagsIsolationTest extends TestCase
{
    private Redis $redis;
    private RedisCache $cache;
    private string $prefix;
    /** @var list<string> */
    private array $sentinels = [];

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('ext-redis is required.');
        }
        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('REDIS_PORT') ?: 6379);
        $this->prefix = 'flush-isolation:' . bin2hex(random_bytes(8)) . ':';
        $this->redis = new Redis();
        $this->redis->connect($host, $port, 2.5);
        $this->cache = new RedisCache($host, $this->prefix, $port);
    }

    protected function tearDown(): void
    {
        if (isset($this->cache)) {
            $this->cache->clear();
        }
        if (isset($this->redis)) {
            if ($this->sentinels !== []) {
                $this->redis->del(...$this->sentinels);
            }
            $this->redis->close();
        }
    }

    private function root(string $prefix): string
    {
        return 'qb:v2:{' . hash('sha256', $prefix) . '}:';
    }

    private function sentinel(string $key): void
    {
        $this->sentinels[] = $key;
        $this->redis->set($key, 'preserve');
        $this->redis->sAdd($this->root($this->prefix) . 'tag:target', $key);
    }

    public function testLegitimateEntryAndItsAssociationsAreInvalidated(): void
    {
        $this->cache->setWithTags('item', 'value', 60, ['target', 'secondary']);
        self::assertTrue($this->cache->flushTags(['target']));
        self::assertFalse($this->cache->has('item'));
        self::assertSame(0, $this->redis->exists($this->root($this->prefix) . 'key-tags:item'));
        self::assertSame([], $this->redis->sMembers($this->root($this->prefix) . 'tag:secondary'));
    }

    public function testExternalOnlyIndexDoesNotDeleteExternalKey(): void
    {
        $external = $this->prefix . 'external';
        $this->sentinel($external);
        self::assertTrue($this->cache->flushTags(['target']));
        self::assertSame('preserve', $this->redis->get($external));
        self::assertSame(0, $this->redis->exists($this->root($this->prefix) . 'tag:target'));
    }

    public function testMixedIndexPreservesOtherNamespacesAndInternalTypes(): void
    {
        $root = $this->root($this->prefix);
        foreach ([
            $this->prefix . 'external',
            $this->root($this->prefix . 'other:') . 'value:item',
            $this->root(substr($this->prefix, 0, -1)) . 'value:item',
            $root . 'lock:remember:item',
            $root . 'lock:swr:item',
            $root . 'tag:unrelated',
            $root . 'key-tags:unrelated',
            $root . 'value-like:item',
        ] as $key) {
            $this->sentinel($key);
        }
        $this->cache->setWithTags('item', 'value', 60, ['target']);
        self::assertTrue($this->cache->flushTags(['target']));
        self::assertFalse($this->cache->has('item'));
        foreach ($this->sentinels as $key) {
            self::assertSame('preserve', $this->redis->get($key), $key);
        }
    }

    public function testSqlWriteStillInvalidatesCachedRead(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $connection = new PDOConnection(new DatabaseSettings('sqlite', '', ':memory:', 0, '', ''));
        $connection->pdo()->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, value TEXT)');
        $builder = new QueryBuilder($connection, cache: $this->cache);
        $builder->insert('items', ['id' => 1, 'value' => 'before'])->execute();
        $read = fn () => (new QueryBuilder($connection, cache: $this->cache))
            ->select('items')->cache(60)->execute();
        self::assertSame('before', $read()->data[0]['value']);
        self::assertSame('before', $read()->data[0]['value']);
        $builder->update('items', ['value' => 'after'])->where('id', '=', 1)->execute();
        self::assertSame('after', $read()->data[0]['value']);
        $connection->disconnect();
    }
}