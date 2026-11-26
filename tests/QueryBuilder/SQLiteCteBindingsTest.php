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

final class SQLiteCteBindingsTest extends TestCase
{
    private PDOConnection $connection;
    private \PDO $pdo;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }

        $this->connection = new PDOConnection(new DatabaseSettings('sqlite', '', ':memory:', 0, '', ''));
        $this->pdo = $this->connection->pdo();
        $this->pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, value TEXT)');
        $this->pdo->exec("INSERT INTO items VALUES (1, 'one'), (2, 'two')");
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->disconnect();
        }
    }

    public function testBothFluentOrdersProduceEquivalentSqlBindingsAndRowsWithThirteenCteParameters(): void
    {
        $loggerBeforeSelect = new CteBindingLogger();
        $loggerAfterSelect = new CteBindingLogger();
        $cteSql = 'SELECT ? AS value WHERE ' . implode(' AND ', array_fill(0, 6, '? = ?'));
        $cteValues = array_merge(['match'], array_fill(0, 12, 1));

        $beforeSelect = (new QueryBuilder($this->connection, logger: $loggerBeforeSelect))
            ->with('source', $cteSql, $cteValues)
            ->select('source', ['value'])
            ->where('value', '=', 'match');
        $afterSelect = (new QueryBuilder($this->connection, logger: $loggerAfterSelect))
            ->select('source', ['value'])
            ->where('value', '=', 'match')
            ->with('source', $cteSql, $cteValues);

        self::assertSame($beforeSelect->getQuerySql(), $afterSelect->getQuerySql());
        self::assertSame($beforeSelect->toSql(true), $afterSelect->toSql(true));
        self::assertSame([['value' => 'match']], $this->rows($beforeSelect->execute()->data));
        self::assertSame([['value' => 'match']], $this->rows($afterSelect->execute()->data));

        $expectedParams = [':param0' => 'match'];
        for ($index = 1; $index <= 12; $index++) {
            $expectedParams[':param' . $index] = 1;
        }
        $expectedParams[':param13'] = 'match';
        self::assertSame($expectedParams, $loggerBeforeSelect->queries[0]['params']);
        self::assertSame($expectedParams, $loggerAfterSelect->queries[0]['params']);
    }

    public function testMarkerTextInsideStringsCommentsAndQuotedIdentifiersIsPreserved(): void
    {
        $logger = new CteBindingLogger();
        $builder = (new QueryBuilder($this->connection, logger: $logger))
            ->select('source', ['value'])
            ->where('value', '=', 'safe')
            ->with(
                'source',
                "SELECT ? AS value, ':param1' AS marker, 1 AS \"column:param0\" /* :param1 */ -- :param0\n",
                ['safe'],
            );

        $sql = $builder->getQuerySql();
        $debugSql = $builder->toSql(true);
        self::assertStringContainsString("':param1'", $sql);
        self::assertStringContainsString('"column:param0"', $sql);
        self::assertStringContainsString('/* :param1 */', $sql);
        self::assertStringContainsString('-- :param0', $sql);
        self::assertStringContainsString("':param1'", $debugSql);
        self::assertStringContainsString('"column:param0"', $debugSql);
        self::assertStringContainsString('/* :param1 */', $debugSql);
        self::assertStringContainsString('-- :param0', $debugSql);
        self::assertSame([['value' => 'safe']], $this->rows($builder->execute()->data));
        self::assertSame([':param0' => 'safe', ':param1' => 'safe'], $logger->queries[0]['params']);

        $namedLogger = new CteBindingLogger();
        $namedBuilder = (new QueryBuilder($this->connection, logger: $namedLogger))
            ->select('named_source', ['value'])
            ->where('value', '=', 'safe')
            ->with(
                'named_source',
                "SELECT :value AS value, ':value' AS marker, 1 AS \"column:value\" /* :value */ -- :value\n",
                ['value' => 'safe'],
            );
        self::assertStringContainsString("':value'", $namedBuilder->getQuerySql());
        self::assertStringContainsString('/* :value */', $namedBuilder->getQuerySql());
        self::assertSame([['value' => 'safe']], $this->rows($namedBuilder->execute()->data));
        self::assertSame([':param0' => 'safe', ':param1' => 'safe'], $namedLogger->queries[0]['params']);
    }

    public function testMultipleParameterizedCtesJoinWithoutBindingCollisions(): void
    {
        $logger = new CteBindingLogger();
        $builder = (new QueryBuilder($this->connection, logger: $logger))
            ->select('items', ['items.value'])
            ->with('first_source', 'SELECT id FROM items WHERE id = ?', [2])
            ->with('second_source', 'SELECT id FROM first_source WHERE id = ?', [2])
            ->join('second_source', 'items.id', '=', 'second_source.id');

        self::assertSame([['value' => 'two']], $this->rows($builder->execute()->data));
        self::assertSame([':param0' => 2, ':param1' => 2], $logger->queries[0]['params']);
    }

    public function testMultipleParameterizedCtesBeforeSelectRetainAllBindings(): void
    {
        $logger = new CteBindingLogger();
        $builder = (new QueryBuilder($this->connection, logger: $logger))
            ->with('first_source', 'SELECT value FROM items WHERE id = ?', [2])
            ->with('second_source', 'SELECT value FROM first_source WHERE value = ?', ['two'])
            ->select('second_source', ['value']);

        self::assertSame([['value' => 'two']], $this->rows($builder->execute()->data));
        self::assertSame([':param0' => 2, ':param1' => 'two'], $logger->queries[0]['params']);
    }

    public function testParameterizedCtesWithInsertUpdateAndDeleteUseDistinctBindings(): void
    {
        $updateLogger = new CteBindingLogger();
        $update = (new QueryBuilder($this->connection, logger: $updateLogger))
            ->with('target', 'SELECT id FROM items WHERE id = ?', [2])
            ->update('items', ['value' => 'updated'])
            ->whereInSubquery('id', 'SELECT id FROM target');
        self::assertSame(1, $update->execute()->count);
        self::assertSame([':param0' => 2, ':param1' => 'updated'], $updateLogger->queries[0]['params']);
        self::assertSame('updated', $this->pdo->query('SELECT value FROM items WHERE id = 2')->fetchColumn());

        $insertLogger = new CteBindingLogger();
        $insert = (new QueryBuilder($this->connection, logger: $insertLogger))
            ->with('insert_source', 'SELECT ? AS value', ['cte-only'])
            ->insert('items', ['id' => 3, 'value' => 'inserted']);
        self::assertSame(1, $insert->execute()->count);
        self::assertSame(
            [':param0' => 'cte-only', ':param1' => 3, ':param2' => 'inserted'],
            $insertLogger->queries[0]['params'],
        );
        self::assertSame('inserted', $this->pdo->query('SELECT value FROM items WHERE id = 3')->fetchColumn());

        $deleteLogger = new CteBindingLogger();
        $delete = (new QueryBuilder($this->connection, logger: $deleteLogger))
            ->with('delete_target', 'SELECT id FROM items WHERE id = ?', [2])
            ->delete('items')
            ->whereInSubquery('id', 'SELECT id FROM delete_target');
        self::assertSame(1, $delete->execute()->count);
        self::assertSame([':param0' => 2], $deleteLogger->queries[0]['params']);
        self::assertFalse($this->pdo->query('SELECT 1 FROM items WHERE id = 2')->fetchColumn());
    }

    public function testFailedParameterizedCteCanBeRetriedAndSuccessfulReuseHasNoResidualBindings(): void
    {
        $logger = new CteBindingLogger();
        $builder = (new QueryBuilder($this->connection, logger: $logger))
            ->with('source', 'SELECT value FROM later_table WHERE id = ?', [7])
            ->select('source', ['value']);
        $expectedSql = $builder->getQuerySql();

        try {
            $builder->execute();
            self::fail('The first execution must fail because later_table is missing.');
        } catch (\Omegaalfa\QueryBuilder\Exceptions\QueryException) {
            self::assertSame($expectedSql, $builder->getQuerySql());
            self::assertSame([':param0' => 7], $logger->errors[0]['params']);
        }

        $this->pdo->exec('CREATE TABLE later_table (id INTEGER, value TEXT)');
        $this->pdo->exec("INSERT INTO later_table VALUES (7, 'retried')");
        self::assertSame([['value' => 'retried']], $this->rows($builder->execute()->data));

        $plainSql = $builder->select('items', ['value'])->where('id', '=', 1)->getQuerySql();
        self::assertSame('SELECT "value" FROM "items" WHERE "id" = :param0', $plainSql);
        self::assertSame([['value' => 'one']], $this->rows($builder->execute()->data));
        self::assertSame(2, count($logger->queries));
    }

    public function testParameterizedCteDmlStillInvalidatesCachedSelect(): void
    {
        $cache = new CteBindingCache();
        $builder = new QueryBuilder($this->connection, cache: $cache);
        $read = fn () => $builder->select('items', ['value'])->where('id', '=', 2)->cache(60)->execute();

        self::assertSame([['value' => 'two']], $this->rows($read()->data));
        $update = $builder
            ->with('target', 'SELECT id FROM items WHERE id = ?', [2])
            ->update('items', ['value' => 'after'])
            ->whereInSubquery('id', 'SELECT id FROM target');
        self::assertSame(1, $update->execute()->count);
        self::assertSame(1, $cache->invalidations);
        self::assertSame([['value' => 'after']], $this->rows($read()->data));
        self::assertSame(2, $cache->sets);
    }

    public function testParameterizedCteBindingsWithConfiguredMysqlAndPostgresql(): void
    {
        $configurations = [];
        if (getenv('DOCKER_MYSQL_HOST') !== false) {
            $configurations[] = [
                'mysql',
                getenv('DOCKER_MYSQL_HOST') ?: 'mysql',
                getenv('DOCKER_MYSQL_DATABASE') ?: 'query_builder_test',
                (int) (getenv('DOCKER_MYSQL_PORT') ?: 3306),
                getenv('DOCKER_MYSQL_USERNAME') ?: 'query_builder',
                getenv('DOCKER_MYSQL_PASSWORD') ?: 'query-builder-test',
            ];
        }
        if (getenv('PGVECTOR_HOST') !== false) {
            $configurations[] = [
                'pgsql',
                getenv('PGVECTOR_HOST') ?: 'pgvector',
                getenv('PGVECTOR_DATABASE') ?: 'query_builder_test',
                (int) (getenv('PGVECTOR_PORT') ?: 5432),
                getenv('PGVECTOR_USERNAME') ?: 'query_builder',
                getenv('PGVECTOR_PASSWORD') ?: 'query-builder-test',
            ];
        }
        if ($configurations === []) {
            self::markTestSkipped('Docker MySQL/PostgreSQL integration credentials are not configured.');
        }

        foreach ($configurations as [$driver, $host, $database, $port, $username, $password]) {
            $pdoDriver = $driver === 'pgsql' ? 'pgsql' : 'mysql';
            if (!in_array($pdoDriver, PDO::getAvailableDrivers(), true)) {
                continue;
            }

            $connection = new PDOConnection(new DatabaseSettings($driver, $host, $database, $port, $username, $password));
            $table = 'qb_cte_bindings_' . bin2hex(random_bytes(6));
            try {
                $pdo = $connection->pdo();
                $pdo->exec("CREATE TABLE {$table} (id INTEGER PRIMARY KEY, value VARCHAR(80) NOT NULL)");
                $pdo->exec("INSERT INTO {$table} VALUES (1, 'before')");

                $read = (new QueryBuilder($connection))
                    ->with('source', "SELECT value FROM {$table} WHERE id = ?", [1])
                    ->select('source', ['value']);
                $result = $read->execute();
                self::assertSame([['value' => 'before']], $this->rows($result->data), "{$driver} CTE SELECT");

                $logger = new CteBindingLogger();
                $update = (new QueryBuilder($connection, logger: $logger))
                    ->with('target', "SELECT id FROM {$table} WHERE value = ?", ['before'])
                    ->update($table, ['value' => 'after'])
                    ->whereInSubquery('id', 'SELECT id FROM target');
                self::assertSame(1, $update->execute()->count, "{$driver} CTE UPDATE count");
                self::assertSame([':param0' => 'before', ':param1' => 'after'], $logger->queries[0]['params']);
                self::assertSame('after', $pdo->query("SELECT value FROM {$table} WHERE id = 1")->fetchColumn());
            } finally {
                if (isset($pdo)) {
                    $pdo->exec("DROP TABLE IF EXISTS {$table}");
                }
                $connection->disconnect();
            }
        }
    }

    private function rows(iterable $rows): array
    {
        return is_array($rows) ? $rows : iterator_to_array($rows, false);
    }
}

final class CteBindingLogger implements QueryLoggerInterface
{
    /** @var list<array{sql: string, params: array<string, mixed>}> */
    public array $queries = [];
    /** @var list<array{sql: string, params: array<string, mixed>}> */
    public array $errors = [];

    public function logQuery(string $sql, array $params, float $duration, int $rowCount): void
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params];
    }

    public function logError(string $sql, array $params, Throwable $error): void
    {
        $this->errors[] = ['sql' => $sql, 'params' => $params];
    }

    public function queryCount(): int { return count($this->queries); }
}

final class CteBindingCache implements CacheInterface
{
    /** @var array<string, array{value: mixed, tags: list<string>}> */
    private array $entries = [];
    public int $sets = 0;
    public int $invalidations = 0;

    public function has(string $key): bool { return isset($this->entries[$key]); }
    public function get(string $key): mixed { return $this->entries[$key]['value'] ?? null; }
    public function set(string $key, mixed $value, int $ttl): void { $this->save($key, $value, []); }
    public function setWithTags(string $key, mixed $value, int $ttl, array $tags): void { $this->save($key, $value, $tags); }

    public function flushTags(array $tags): bool
    {
        $this->invalidations++;
        foreach ($this->entries as $key => $entry) {
            if (array_intersect($tags, $entry['tags']) !== []) {
                unset($this->entries[$key]);
            }
        }
        return true;
    }

    public function delete(string $key): void { unset($this->entries[$key]); }
    public function deletePattern(string $pattern): bool { return true; }
    public function clear(): bool { $this->entries = []; return true; }
    public function getMultiple(array $keys): array { return array_intersect_key($this->entries, array_flip($keys)); }

    private function save(string $key, mixed $value, array $tags): void
    {
        $this->sets++;
        $this->entries[$key] = ['value' => $value, 'tags' => $tags];
    }
}