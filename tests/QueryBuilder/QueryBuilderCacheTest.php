<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;


use Omegaalfa\QueryBuilder\QueryBuilder;
use Omegaalfa\QueryBuilder\QueryResultDTO;
use Omegaalfa\QueryBuilder\interfaces\CacheInterface;
use Omegaalfa\QueryBuilder\interfaces\ConnectionInterface;
use Omegaalfa\QueryBuilder\interfaces\PaginatorInterface;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

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
            public function pdo(): PDO { return $this->pdo; }
            public function getDriver(): string { return 'mysql'; }
            public function transaction(callable $callback): mixed { return $callback($this->pdo); }

            public function connect(): PDO
            {
                // TODO: Implement connect() method.
            }

            public function disconnect(): void
            {
                // TODO: Implement disconnect() method.
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
}