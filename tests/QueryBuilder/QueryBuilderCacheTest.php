<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;


use ArrayIterator;
use Omegaalfa\QueryBuilder\QueryBuilder;
use Omegaalfa\QueryBuilder\QueryResultDTO;
use Omegaalfa\QueryBuilder\interfaces\CacheInterface;
use Omegaalfa\QueryBuilder\interfaces\ConnectionInterface;
use Omegaalfa\QueryBuilder\interfaces\PaginatorInterface;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class QueryBuilderCacheTest extends TestCase
{
    public function testExecuteSupportsLazyStreamingAndCache(): void
    {
        // 🔹 Simula um PDOStatement com 2 linhas
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetch')
            ->willReturnOnConsecutiveCalls(
                ['id' => 1, 'nome' => 'A'],
                ['id' => 2, 'nome' => 'B'],
                false
            );
        $stmt->method('rowCount')->willReturn(2);

        // 🔹 Fake PDO que retorna o mock acima
        $fakePdo = new class($stmt) extends PDO {
            private PDOStatement $stmt;
            public function __construct($stmt) { $this->stmt = $stmt; }
            public function prepare($sql, $options = null) { return $this->stmt; }
            public function lastInsertId($name = null): string|false { return '1'; }
        };

        // 🔹 Implementação mínima de ConnectionInterface
        $connection = new class($fakePdo) implements ConnectionInterface {
            public function __construct(private $pdo) {}
            public function getDriver(): string { return 'mysql'; }
            public function transaction(callable $callback): mixed { return $callback($this->pdo); }

            public function pdo(bool $bufferedQuery = true): PDO
            {
                return $this->pdo;
            }

            public function disconnect(): void
            {
                // TODO: Implement disconnect() method.
            }

            public function connect(): void
            {
                // TODO: Implement connect() method.
            }
        };

        // 🔹 Mock básico do paginador
        $paginator = $this->createMock(PaginatorInterface::class);

        // 🔹 Cache fake em memória
        $cache = new class implements CacheInterface {
            private array $store = [];
            public function has(string $key): bool { return isset($this->store[$key]); }
            public function get(string $key): mixed { return $this->store[$key] ?? null; }
            public function set(string $key, mixed $value, int $ttl = 3600): void { $this->store[$key] = $value; }

            public function delete(string $key): void
            {
                // TODO: Implement delete() method.
            }

            public function deletePattern(string $pattern): bool
            {
                // TODO: Implement deletePattern() method.
            }

            public function clear(): bool
            {
                // TODO: Implement clear() method.
            }

            public function getMultiple(array $keys): array
            {
                // TODO: Implement getMultiple() method.
            }
        };

        // 🔹 Cria o QueryBuilder
        $qb = new QueryBuilder($connection, $paginator, $cache);

        // 1️⃣ — Primeira execução (sem cache) → streaming (Generator)
        $result1 = $qb->select('doenca')->cache(10)->execute();
        $this->assertInstanceOf(QueryResultDTO::class, $result1);
        $this->assertIsIterable($result1->data);

        $rows = iterator_to_array($result1->data, false);
        $this->assertCount(2, $rows);
        $this->assertSame('A', $rows[0]['nome']);

        // 2️⃣ — Segunda execução (com cache) → array direto
        $result2 = $qb->select('doenca')->cache(10)->execute();
        $this->assertIsArray($result2->data);
        $this->assertCount(2, $result2->data);
        $this->assertSame('B', $result2->data[1]['nome']);
    }

    public function testGetFromCacheReturnsDtoWhenCachePayloadIsValid(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $paginator = $this->createMock(PaginatorInterface::class);
        $cache = $this->createMock(CacheInterface::class);

        $qb = new QueryBuilder($connection, $paginator, $cache);
        $qb->select('doenca')->cache(60);

        $reflection = new ReflectionClass($qb);
        $generateCacheKey = $reflection->getMethod('generateCacheKey');
        $generateCacheKey->setAccessible(true);
        $expectedKey = $generateCacheKey->invoke($qb);

        $payload = [
            'data' => [['id' => 1, 'nome' => 'cached']],
            'count' => 1,
            'pagination' => null
        ];

        $cache->expects($this->once())
            ->method('has')
            ->with($expectedKey)
            ->willReturn(true);

        $cache->expects($this->once())
            ->method('get')
            ->with($expectedKey)
            ->willReturn($payload);

        $getFromCache = $reflection->getMethod('getFromCache');
        $getFromCache->setAccessible(true);

        $cached = $getFromCache->invoke($qb);

        $this->assertInstanceOf(QueryResultDTO::class, $cached);
        $this->assertSame($payload['data'], $cached->data);
        $this->assertSame($payload['count'], $cached->count);
        $this->assertNull($cached->pagination);
    }

    public function testGetFromCacheReturnsNullWhenPayloadIsInvalid(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $paginator = $this->createMock(PaginatorInterface::class);
        $cache = $this->createMock(CacheInterface::class);

        $qb = new QueryBuilder($connection, $paginator, $cache);
        $qb->select('doenca')->cache(60);

        $reflection = new ReflectionClass($qb);
        $generateCacheKey = $reflection->getMethod('generateCacheKey');
        $generateCacheKey->setAccessible(true);
        $expectedKey = $generateCacheKey->invoke($qb);

        $cache->expects($this->once())
            ->method('has')
            ->with($expectedKey)
            ->willReturn(true);

        $cache->expects($this->once())
            ->method('get')
            ->with($expectedKey)
            ->willReturn('unexpected');

        $getFromCache = $reflection->getMethod('getFromCache');
        $getFromCache->setAccessible(true);

        $this->assertNull($getFromCache->invoke($qb));
    }

    public function testSaveToCacheStoresIterableResult(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $paginator = $this->createMock(PaginatorInterface::class);
        $cache = $this->createMock(CacheInterface::class);

        $qb = new QueryBuilder($connection, $paginator, $cache);
        $qb->cache(180);

        $reflection = new ReflectionClass($qb);
        $cacheKeyProperty = $reflection->getProperty('cacheKey');
        $cacheKeyProperty->setAccessible(true);
        $cacheKeyProperty->setValue($qb, 'test:cache:key');

        $cache->expects($this->once())
            ->method('set')
            ->with(
                'test:cache:key',
                $this->callback(function (array $payload): bool {
                    return $payload['data'] === [['id' => 2]]
                        && $payload['count'] === 1
                        && $payload['pagination'] === null
                        && $payload['ttl'] === 180
                        && isset($payload['cached_at']);
                }),
                180
            );

        $saveToCache = $reflection->getMethod('saveToCache');
        $saveToCache->setAccessible(true);

        $data = new ArrayIterator([['id' => 2]]);
        $result = new QueryResultDTO($data, 1);

        $saveToCache->invoke($qb, $result);
    }
}
