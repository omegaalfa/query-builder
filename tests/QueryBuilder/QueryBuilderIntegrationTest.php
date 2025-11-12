<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\QueryBuilder;
use Omegaalfa\QueryBuilder\interfaces\ConnectionInterface;
use Omegaalfa\QueryBuilder\interfaces\PaginatorInterface;
use Omegaalfa\QueryBuilder\PaginationDTO;
use Omegaalfa\QueryBuilder\QueryBuilderOperations;
use PHPUnit\Framework\TestCase;
use PDO;

final class QueryBuilderIntegrationTest extends TestCase
{
    private PDO $pdo;
    private QueryBuilder $qb;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension is not available; skipping integration tests that require SQLite.');
        }

        // Create in-memory SQLite and table
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        $this->pdo->exec(<<<'SQL'
CREATE TABLE users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT,
  age INTEGER,
  status INTEGER,
  value REAL
);
SQL
        );

        // Implement a minimal ConnectionInterface backed by the PDO above
        $connection = new class($this->pdo) implements ConnectionInterface {
            public function __construct(private PDO $pdo) {}
            public function connect(): void {}
            public function pdo(bool $bufferedQuery = true): PDO { return $this->pdo; }
            public function disconnect(): void {}
            public function transaction(callable $callback): mixed
            {
                $this->pdo->beginTransaction();
                try {
                    $res = $callback($this->pdo);
                    $this->pdo->commit();
                    return $res;
                } catch (\Throwable $e) {
                    $this->pdo->rollBack();
                    throw $e;
                }
            }
            public function getDriver(): string { return 'sqlite'; }
        };

        // Minimal paginator that returns simple page info
        $paginator = new class implements PaginatorInterface {
            public function paginate(int $total, int $perPage, int $currentPage): PaginationDTO
            {
                $totalPages = (int)ceil($total / max(1, $perPage));
                return new PaginationDTO($currentPage, $perPage, $totalPages, $total);
            }
        };

        $this->qb = new QueryBuilder($connection, $paginator, null, null);
    }

    public function testInsertSelectCountSumAvg(): void
    {
        // Insert rows
        $this->qb->insert('users', ['name' => 'Alice', 'age' => 30, 'status' => 1, 'value' => 10.5])->execute();
        $this->qb->insert('users', ['name' => 'Bob', 'age' => 40, 'status' => 1, 'value' => 20.0])->execute();
        $this->qb->insert('users', ['name' => 'Eve', 'age' => 25, 'status' => 0, 'value' => 5.0])->execute();

        // Count total
        $count = $this->qb->select('users')->count();
        $this->assertSame(3, $count);

        // Sum of value for status=1
        $sum = $this->qb->select('users')->where('status', 'EQUALS', 1)->sum('value');
        $this->assertEqualsWithDelta(30.5, $sum, 0.001);

        // Avg age
        $avg = $this->qb->select('users')->avg('age');
        $this->assertEqualsWithDelta((30+40+25)/3, $avg, 0.001);
    }

    public function testUpdateAndDelete(): void
    {
        $this->qb->insert('users', ['name' => 'Carol', 'age' => 50, 'status' => 1, 'value' => 7.0])->execute();

        // Update
        $this->qb->update('users', ['value' => 9.5])->where('name', 'EQUALS', 'Carol')->execute();
        $res = $this->qb->select('users', ['value'])->where('name', 'EQUALS', 'Carol')->execute();
        $data = iterator_to_array($res->data);
        $this->assertEqualsWithDelta(9.5, (float)$data[0]['value'], 0.001);

        // Delete
        $this->qb->delete('users')->where('name', 'EQUALS', 'Carol')->execute();
        $this->assertSame(0, $this->qb->select('users')->where('name', 'EQUALS', 'Carol')->count());
    }

    public function testTransactionRollback(): void
    {
        $initial = $this->qb->select('users')->count();

        try {
            $this->qb->transactional(function($qb, PDO $pdo) {
                $qb->insert('users', ['name' => 'TxUser', 'age' => 20, 'status' => 1, 'value' => 1.0])->execute();
                throw new \RuntimeException('force rollback');
            });
        } catch (\RuntimeException $e) {
            // expected
        }

        $after = $this->qb->select('users')->count();
        $this->assertSame($initial, $after);
    }

    public function testPaginationLimitAndGetTotalCount(): void
    {
        // Ensure there are multiple rows
        for ($i = 0; $i < 5; $i++) {
            $this->qb->insert('users', ['name' => 'U' . $i, 'age' => 20 + $i, 'status' => 1, 'value' => $i])->execute();
        }

        $result = $this->qb->select('users', ['id', 'name'])->limit(2, 0)->execute();
        $data = iterator_to_array($result->data);
        $this->assertCount(2, $data);
        $this->assertNotNull($result->pagination);
        $this->assertSame( (int) ceil($this->qb->select('users')->count() / 2), $result->pagination->totalPages);
    }
}
