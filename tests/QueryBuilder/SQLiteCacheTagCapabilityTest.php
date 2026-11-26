<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\Interfaces\CacheInterface;
use Omegaalfa\QueryBuilder\Interfaces\QueryLoggerInterface;
use Omegaalfa\QueryBuilder\QueryBuilder;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

final class SQLiteCacheTagCapabilityTest extends TestCase
{
    /** @var list<PDOConnection> */
    private array $connections = [];

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            $connection->disconnect();
        }
        foreach ($this->files as $file) {
            foreach ([$file, $file . '-journal', $file . '-wal', $file . '-shm'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function testCacheInterfaceOnlyBackendIsNotReadOrWrittenAndSelectRunsAgain(): void
    {
        $connection = $this->memoryConnection();
        $pdo = $connection->pdo();
        $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, value TEXT)');
        $pdo->exec("INSERT INTO items VALUES (1, 'first')");
        $cache = new InterfaceOnlyAuditCache();
        $logger = new CacheCapabilityLogger();
        $builder = new QueryBuilder($connection, cache: $cache, logger: $logger);

        $sql = $builder->select('items', ['value'])->where('id', '=', 1)->cache(300)->getQuerySql();
        $first = $builder->execute();
        $pdo->exec("UPDATE items SET value = 'fresh' WHERE id = 1");
        $second = $builder->select('items', ['value'])->where('id', '=', 1)->cache(300)->execute();

        self::assertSame([['value' => 'first']], $this->rows($first->data));
        self::assertSame([['value' => 'fresh']], $this->rows($second->data));
        self::assertSame(0, $cache->lookups);
        self::assertSame(0, $cache->sets);
        self::assertSame(2, $logger->queryCount());
        self::assertSame([$sql, $sql], array_column($logger->queries, 'sql'));
        self::assertSame([0, 0], array_column($logger->queries, 'count'));
        self::assertSame(0, $first->count);
        self::assertSame(0, $second->count);
    }

    public function testInsertUpdateAndDeleteStillExecuteWithInterfaceOnlyBackend(): void
    {
        $connection = $this->memoryConnection();
        $pdo = $connection->pdo();
        $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, value TEXT)');
        $cache = new InterfaceOnlyAuditCache();

        $insert = $this->builder($connection, $cache)->insert('items', ['id' => 1, 'value' => 'inserted'])->execute();
        $update = $this->builder($connection, $cache)->update('items', ['value' => 'updated'])->where('id', '=', 1)->execute();
        $delete = $this->builder($connection, $cache)->delete('items')->where('id', '=', 1)->execute();

        self::assertSame(1, $insert->count);
        self::assertSame(1, $update->count);
        self::assertSame(1, $delete->count);
        self::assertSame(0, $cache->lookups);
        self::assertSame(0, $cache->sets);
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM items')->fetchColumn());
    }

    public function testTagAwareBackendStillHitsAndWriteInvalidates(): void
    {
        $connection = $this->memoryConnection();
        $pdo = $connection->pdo();
        $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, value TEXT)');
        $pdo->exec("INSERT INTO items VALUES (1, 'before')");
        $cache = new TagAwareCapabilityCache();
        $read = fn () => $this->builder($connection, $cache)
            ->select('items', ['value'])
            ->where('id', '=', 1)
            ->cache(300)
            ->execute();

        self::assertSame([['value' => 'before']], $read()->data);
        self::assertSame([['value' => 'before']], $read()->data);
        self::assertSame(1, $cache->hits);
        self::assertSame(1, $cache->sets);

        $this->builder($connection, $cache)->update('items', ['value' => 'after'])->where('id', '=', 1)->execute();
        self::assertSame([['value' => 'after']], $read()->data);
        self::assertSame(2, $cache->sets);
        self::assertSame(1, $cache->invalidations);
    }

    public function testTwoBuildersSharingUntaggedCacheCannotReuseStaleResult(): void
    {
        $connection = $this->memoryConnection();
        $connection->pdo()->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, value TEXT)');
        $connection->pdo()->exec("INSERT INTO items VALUES (1, 'old')");
        $cache = new InterfaceOnlyAuditCache();
        $reader = $this->builder($connection, $cache);
        $writer = $this->builder($connection, $cache);

        $first = $reader->select('items', ['value'])->cache(300)->execute();
        $writer->update('items', ['value' => 'new'])->execute();
        $second = $reader->select('items', ['value'])->cache(300)->execute();

        self::assertSame([['value' => 'old']], $this->rows($first->data));
        self::assertSame([['value' => 'new']], $this->rows($second->data));
        self::assertSame(0, $cache->lookups);
        self::assertSame(0, $cache->sets);
    }

    public function testTwoConnectionsToSameFileDoNotShareStaleResult(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'qb-no-tags-');
        self::assertNotFalse($file);
        $this->files[] = $file;
        $firstConnection = $this->fileConnection($file);
        $firstConnection->pdo()->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, value TEXT)');
        $firstConnection->pdo()->exec("INSERT INTO items VALUES (1, 'before')");
        $secondConnection = $this->fileConnection($file);
        $cache = new InterfaceOnlyAuditCache();

        $first = $this->builder($firstConnection, $cache)->select('items', ['value'])->cache(300)->execute();
        self::assertSame([['value' => 'before']], $this->rows($first->data));
        $this->builder($secondConnection, $cache)->update('items', ['value' => 'after'])->execute();
        $second = $this->builder($firstConnection, $cache)->select('items', ['value'])->cache(300)->execute();

        self::assertSame([['value' => 'after']], $this->rows($second->data));
        self::assertSame(0, $cache->lookups);
        self::assertSame(0, $cache->sets);
    }

    public function testCacheReadAndWriteFailuresDoNotPreventSqlExecution(): void
    {
        $connection = $this->memoryConnection();
        $pdo = $connection->pdo();
        $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, value TEXT)');
        $pdo->exec("INSERT INTO items VALUES (1, 'available')");
        $cache = new FailingTagAwareCapabilityCache(failRead: true, failWrite: true);
        $logger = new CacheCapabilityLogger();

        $result = $this->builder($connection, $cache, $logger)
            ->select('items', ['value'])
            ->where('id', '=', 1)
            ->cache(300)
            ->execute();

        self::assertSame([['value' => 'available']], $this->rows($result->data));
        self::assertSame(1, $logger->queryCount());
        self::assertSame('available', $pdo->query('SELECT value FROM items WHERE id=1')->fetchColumn());
        self::assertSame(1, $cache->reads);
        self::assertSame(1, $cache->writes);
    }

    private function builder(
        PDOConnection $connection,
        CacheInterface $cache,
        ?CacheCapabilityLogger $logger = null,
    ): QueryBuilder {
        return new QueryBuilder($connection, cache: $cache, logger: $logger);
    }

    private function memoryConnection(): PDOConnection
    {
        return $this->newConnection(':memory:');
    }

    private function fileConnection(string $file): PDOConnection
    {
        return $this->newConnection($file);
    }

    private function newConnection(string $database): PDOConnection
    {
        $connection = new PDOConnection(new DatabaseSettings('sqlite', '', $database, 0, '', ''));
        $this->connections[] = $connection;
        return $connection;
    }

    private function rows(iterable $data): array
    {
        return is_array($data) ? $data : iterator_to_array($data, false);
    }
}

final class InterfaceOnlyAuditCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $entries = [];
    public int $lookups = 0;
    public int $sets = 0;

    public function has(string $key): bool { $this->lookups++; return array_key_exists($key, $this->entries); }
    public function get(string $key): mixed { $this->lookups++; return $this->entries[$key] ?? null; }
    public function set(string $key, mixed $value, int $ttl): void { $this->sets++; $this->entries[$key] = $value; }
    public function delete(string $key): void { unset($this->entries[$key]); }
    public function deletePattern(string $pattern): bool { return true; }
    public function clear(): bool { $this->entries = []; return true; }
    public function getMultiple(array $keys): array { return array_intersect_key($this->entries, array_flip($keys)); }
}

class TagAwareCapabilityCache implements CacheInterface
{
    /** @var array<string, mixed> */
    protected array $entries = [];
    /** @var array<string, list<string>> */
    protected array $tagsByKey = [];
    public int $hits = 0;
    public int $sets = 0;
    public int $invalidations = 0;

    public function has(string $key): bool { return array_key_exists($key, $this->entries); }
    public function get(string $key): mixed
    {
        if (array_key_exists($key, $this->entries)) { $this->hits++; }
        return $this->entries[$key] ?? null;
    }
    public function set(string $key, mixed $value, int $ttl): void { $this->save($key, $value, []); }
    public function setWithTags(string $key, mixed $value, int $ttl, array $tags): void { $this->save($key, $value, $tags); }
    public function flushTags(array $tags): bool
    {
        $this->invalidations++;
        foreach ($this->tagsByKey as $key => $entryTags) {
            if (array_intersect($tags, $entryTags) !== []) { unset($this->tagsByKey[$key], $this->entries[$key]); }
        }
        return true;
    }
    public function delete(string $key): void { unset($this->entries[$key], $this->tagsByKey[$key]); }
    public function deletePattern(string $pattern): bool { return true; }
    public function clear(): bool { $this->entries = []; $this->tagsByKey = []; return true; }
    public function getMultiple(array $keys): array { return array_intersect_key($this->entries, array_flip($keys)); }
    protected function save(string $key, mixed $value, array $tags): void
    {
        $this->sets++;
        $this->entries[$key] = $value;
        $this->tagsByKey[$key] = $tags;
    }
}

final class FailingTagAwareCapabilityCache extends TagAwareCapabilityCache
{
    public int $reads = 0;
    public int $writes = 0;

    public function __construct(private bool $failRead, private bool $failWrite) {}

    public function has(string $key): bool
    {
        $this->reads++;
        if ($this->failRead) { throw new \RuntimeException('cache read unavailable'); }
        return parent::has($key);
    }

    public function setWithTags(string $key, mixed $value, int $ttl, array $tags): void
    {
        $this->writes++;
        if ($this->failWrite) { throw new \RuntimeException('cache write unavailable'); }
        parent::setWithTags($key, $value, $ttl, $tags);
    }
}

final class CacheCapabilityLogger implements QueryLoggerInterface
{
    /** @var list<array{sql: string, count: int}> */
    public array $queries = [];

    public function logQuery(string $sql, array $params, float $duration, int $rowCount): void
    {
        $this->queries[] = ['sql' => $sql, 'count' => $rowCount];
    }
    public function logError(string $sql, array $params, Throwable $error): void {}
    public function queryCount(): int { return count($this->queries); }
}