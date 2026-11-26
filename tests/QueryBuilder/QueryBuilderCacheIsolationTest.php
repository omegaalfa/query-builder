<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\Interfaces\CacheInterface;
use Omegaalfa\QueryBuilder\Interfaces\ConnectionInterface;
use Omegaalfa\QueryBuilder\Interfaces\QueryLoggerInterface;
use Omegaalfa\QueryBuilder\QueryBuilder;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Throwable;

final class QueryBuilderCacheIsolationTest extends TestCase
{
    private SharedQueryCache $cache;

    /** @var list<PDOConnection> */
    private array $connections = [];

    /** @var list<string> */
    private array $databasePaths = [];

    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }
        $this->cache = new SharedQueryCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            $connection->disconnect();
        }
        foreach ($this->databasePaths as $path) {
            foreach ([$path, $path . '-wal', $path . '-shm', $path . '-journal'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        foreach ($this->temporaryDirectories as $directory) {
            rmdir($directory);
        }
    }

    public function testIndependentMemoryConnectionsWithSameSettingsDoNotShareResults(): void
    {
        $firstConnection = $this->pdoConnection(':memory:');
        $secondConnection = $this->pdoConnection(':memory:');
        $this->initialize($firstConnection, 'first-memory');
        $this->initialize($secondConnection, 'second-memory');

        $first = $this->readName($firstConnection);
        $second = $this->readName($secondConnection);

        self::assertSame([['name' => 'first-memory']], iterator_to_array($first->data, false));
        self::assertSame([['name' => 'second-memory']], iterator_to_array($second->data, false));
        self::assertSame(0, $this->cache->hits);
        self::assertSame(2, $this->cache->sets);
    }

    public function testSameSqliteFileSharesResultsAcrossAbsoluteAndRelativePaths(): void
    {
        $directory = $this->temporaryDirectory();
        $path = $directory . DIRECTORY_SEPARATOR . 'shared.sqlite';
        $this->databasePaths[] = $path;
        $absoluteConnection = $this->pdoConnection($path);
        $this->initialize($absoluteConnection, 'same-file');

        $previousDirectory = getcwd();
        self::assertNotFalse($previousDirectory);
        try {
            self::assertTrue(chdir($directory));
            $relativeConnection = $this->pdoConnection('shared.sqlite');
            self::assertSame(1, (int) $relativeConnection->pdo()->query('SELECT 1')->fetchColumn());

            $first = $this->readName($absoluteConnection);
            $second = $this->readName($relativeConnection);

            self::assertSame([['name' => 'same-file']], iterator_to_array($first->data, false));
            self::assertSame([['name' => 'same-file']], $second->data);
            self::assertSame(1, $this->cache->hits);
            self::assertSame(1, $this->cache->sets);
        } finally {
            chdir($previousDirectory);
        }
    }

    public function testDistinctSqliteFilesWithIdenticalSchemaAndQueryAreIsolated(): void
    {
        $firstPath = $this->temporaryDatabasePath();
        $secondPath = $this->temporaryDatabasePath();
        self::assertNotSame($firstPath, $secondPath);
        $firstConnection = $this->pdoConnection($firstPath);
        $secondConnection = $this->pdoConnection($secondPath);
        $this->initialize($firstConnection, 'database-one');
        $this->initialize($secondConnection, 'database-two');

        $first = $this->readName($firstConnection);
        $second = $this->readName($secondConnection);

        self::assertSame([['name' => 'database-one']], iterator_to_array($first->data, false));
        self::assertSame([['name' => 'database-two']], iterator_to_array($second->data, false));
        self::assertSame(0, $this->cache->hits);
        self::assertSame(2, $this->cache->sets);
    }

    public function testGenericConnectionsAreIsolatedButBuildersShareTheirConnectionIdentity(): void
    {
        $firstPdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $secondPdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->initializePdo($firstPdo, 'generic-one');
        $this->initializePdo($secondPdo, 'generic-two');
        $firstConnection = $this->genericConnection($firstPdo);
        $secondConnection = $this->genericConnection($secondPdo);

        $first = $this->readName($firstConnection);
        $sameConnection = $this->readName($firstConnection);
        $second = $this->readName($secondConnection);

        self::assertSame([['name' => 'generic-one']], iterator_to_array($first->data, false));
        self::assertSame([['name' => 'generic-one']], $sameConnection->data);
        self::assertSame([['name' => 'generic-two']], iterator_to_array($second->data, false));
        self::assertSame(1, $this->cache->hits);
        self::assertSame(2, $this->cache->sets);
    }

    public function testSameConnectionCanShareCacheBetweenBuilderInstances(): void
    {
        $connection = $this->pdoConnection(':memory:');
        $this->initialize($connection, 'shared-connection');

        $first = $this->readName($connection);
        $secondBuilder = new QueryBuilder($connection, cache: $this->cache);
        $second = $secondBuilder->select('items', ['name'])->cache(300)->execute();

        self::assertSame([['name' => 'shared-connection']], iterator_to_array($first->data, false));
        self::assertSame([['name' => 'shared-connection']], $second->data);
        self::assertSame(1, $this->cache->hits);
    }

    public function testMySqlAndPostgreSqlSettingsAffectIdentityWithoutExposingCredentials(): void
    {
        foreach (['mysql', 'pgsql'] as $driver) {
            $port = $driver === 'mysql' ? 3306 : 5432;
            $base = new DatabaseSettings($driver, 'db.internal', 'application', $port, 'app-user-marker', 'secret-marker');
            $sameDatabase = new DatabaseSettings($driver, 'db.internal', 'application', $port, 'app-user-marker', 'rotated-secret-marker');
            $differentHost = new DatabaseSettings($driver, 'other.internal', 'application', $port, 'app-user-marker', 'secret-marker');
            $differentDatabase = new DatabaseSettings($driver, 'db.internal', 'other_database', $port, 'app-user-marker', 'secret-marker');
            $differentPort = new DatabaseSettings($driver, 'db.internal', 'application', $port + 1, 'app-user-marker', 'secret-marker');
            $differentUser = new DatabaseSettings($driver, 'db.internal', 'application', $port, 'other-user', 'secret-marker');
            $differentOptions = new DatabaseSettings(
                $driver,
                'db.internal',
                'application',
                $port,
                'app-user-marker',
                'secret-marker',
                options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT],
            );

            $baseKey = $this->cacheKeyForSettings($base);
            self::assertSame($baseKey, $this->cacheKeyForSettings($sameDatabase));
            self::assertNotSame($baseKey, $this->cacheKeyForSettings($differentHost));
            self::assertNotSame($baseKey, $this->cacheKeyForSettings($differentDatabase));
            self::assertNotSame($baseKey, $this->cacheKeyForSettings($differentPort));
            self::assertNotSame($baseKey, $this->cacheKeyForSettings($differentUser));
            self::assertNotSame($baseKey, $this->cacheKeyForSettings($differentOptions));
            self::assertStringNotContainsString('secret-marker', $baseKey);
            self::assertStringNotContainsString('app-user-marker', $baseKey);
            self::assertStringNotContainsString('db.internal', $baseKey);
        }
    }

    public function testCredentialsDoNotAppearInCacheKeysOrQueryLogs(): void
    {
        $secret = 'sqlite-password-marker';
        $connection = $this->pdoConnection(':memory:', password: $secret);
        $connection->pdo()->exec('CREATE TABLE items (name TEXT)');
        $connection->pdo()->exec("INSERT INTO items VALUES ('logged')");
        $logger = new CacheIsolationLogger();
        $builder = new QueryBuilder($connection, cache: $this->cache, logger: $logger);
        $builder->select('items', ['name'])->cache(300);
        $key = (new ReflectionClass($builder))->getMethod('generateCacheKey')->invoke($builder);
        $builder->execute();

        self::assertStringNotContainsString($secret, $key);
        self::assertStringNotContainsString($secret, json_encode($logger->queries, JSON_THROW_ON_ERROR));
        self::assertSame(1, count($logger->queries));
    }

    private function pdoConnection(string $database, string $password = ''): PDOConnection
    {
        $settings = new DatabaseSettings('sqlite', '', $database, 0, '', $password);
        $connection = new PDOConnection($settings);
        $this->connections[] = $connection;
        return $connection;
    }

    private function initialize(PDOConnection $connection, string $name): void
    {
        $this->initializePdo($connection->pdo(), $name);
    }

    private function initializePdo(PDO $pdo, string $name): void
    {
        $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)');
        $statement = $pdo->prepare('INSERT INTO items (id, name) VALUES (1, :name)');
        $statement->execute(['name' => $name]);
    }

    private function readName(ConnectionInterface $connection): \Omegaalfa\QueryBuilder\QueryResultDTO
    {
        return (new QueryBuilder($connection, cache: $this->cache))
            ->select('items', ['name'])
            ->cache(300)
            ->execute();
    }

    private function genericConnection(PDO $pdo): ConnectionInterface
    {
        return new class($pdo) implements ConnectionInterface {
            public function __construct(private PDO $pdo) {}
            public function connect(): void {}
            public function pdo(bool $bufferedQuery = true): PDO { return $this->pdo; }
            public function disconnect(): void {}
            public function transaction(callable $callback): mixed { return $callback($this->pdo); }
            public function getDriver(): string { return 'sqlite'; }
        };
    }

    private function cacheKeyForSettings(DatabaseSettings $settings): string
    {
        $connection = new class($settings) implements ConnectionInterface {
            public function __construct(private DatabaseSettings $settings) {}
            public function connect(): void {}
            public function pdo(bool $bufferedQuery = true): PDO { throw new \LogicException('Not used for cache-key inspection.'); }
            public function disconnect(): void {}
            public function transaction(callable $callback): mixed { throw new \LogicException('Not used for cache-key inspection.'); }
            public function getDriver(): string { return $this->settings->driver; }
            public function getDbSettings(): DatabaseSettings { return $this->settings; }
        };
        $builder = (new QueryBuilder($connection, cache: $this->cache))->select('items')->cache(300);
        return (new ReflectionClass($builder))->getMethod('generateCacheKey')->invoke($builder);
    }

    private function temporaryDatabasePath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'qb-cache-isolation-');
        self::assertNotFalse($path);
        $this->databasePaths[] = $path;
        return $path;
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qb-cache-isolation-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $this->temporaryDirectories[] = $directory;
        return $directory;
    }
}

final class SharedQueryCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $items = [];
    public int $hits = 0;
    public int $sets = 0;

    public function has(string $key): bool { return array_key_exists($key, $this->items); }
    public function get(string $key): mixed { if (isset($this->items[$key])) ++$this->hits; return $this->items[$key] ?? null; }
    public function set(string $key, mixed $value, int $ttl): void { ++$this->sets; $this->items[$key] = $value; }
    public function setWithTags(string $key, mixed $value, int $ttl, array $tags): void { $this->set($key, $value, $ttl); }
    public function flushTags(array $tags): bool { return true; }
    public function delete(string $key): void { unset($this->items[$key]); }
    public function deletePattern(string $pattern): bool { return true; }
    public function clear(): bool { $this->items = []; return true; }
    public function getMultiple(array $keys): array { return array_intersect_key($this->items, array_flip($keys)); }
}

final class CacheIsolationLogger implements QueryLoggerInterface
{
    /** @var list<array{sql: string, params: array<string, mixed>}> */
    public array $queries = [];

    public function logQuery(string $sql, array $params, float $duration, int $rowCount): void
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params];
    }

    public function logError(string $sql, array $params, Throwable $error): void {}
}
