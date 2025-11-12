<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\QueryBuilder;
use Omegaalfa\QueryBuilder\interfaces\ConnectionInterface;
use Omegaalfa\QueryBuilder\interfaces\PaginatorInterface;
use Omegaalfa\QueryBuilder\PaginationDTO;
use PHPUnit\Framework\TestCase;
use PDO;
use PDOStatement;

final class QueryBuilderUnitTest extends TestCase
{
    public function testAggregateMethodsUsePreparedStatementAndReturnValues(): void
    {
        // Mock PDOStatement to return a single row with total/average/etc
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->any())->method('execute')->willReturn(true);
        $stmt->expects($this->any())->method('rowCount')->willReturn(1);
        // fetch will return ['total' => '123'] then false
        $callCount = 0;
        $stmt->expects($this->any())->method('fetch')->willReturnCallback(function () use (&$callCount) {
            if ($callCount++ === 0) {
                return ['total' => '123'];
            }
            return false;
        });

        // Mock PDO to return the mocked statement
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->any())->method('prepare')->willReturn($stmt);
        $pdo->expects($this->any())->method('lastInsertId')->willReturn('1');

        // Mock connection to return our PDO
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->any())->method('pdo')->willReturn($pdo);
        $connection->expects($this->any())->method('getDriver')->willReturn('sqlite');

        // Simple paginator stub
        $paginator = new class implements PaginatorInterface {
            public function paginate(int $total, int $perPage, int $currentPage): PaginationDTO
            {
                return new PaginationDTO($currentPage, $perPage, 1, $total);
            }
        };

        $qb = new QueryBuilder($connection, $paginator, null, null);

    // sum
    $sum = $qb->select('t', ['x'])->sum('x');
    $this->assertIsFloat($sum);
    $this->assertGreaterThanOrEqual(0, $sum);

    // count via count()
    $count = $qb->select('t')->count();
    $this->assertIsInt($count);
    }

    public function testTransactionalDelegatesToConnection(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $pdoMock = $this->createMock(PDO::class);
        $connection->expects($this->once())->method('transaction')->willReturnCallback(function ($cb) use ($pdoMock) {
            return $cb($pdoMock);
        });
        $connection->expects($this->any())->method('getDriver')->willReturn('sqlite');

        $paginator = $this->createMock(PaginatorInterface::class);

        $qb = new QueryBuilder($connection, $paginator, null, null);

        $result = $qb->transactional(function ($qbArg, $pdoArg) {
            return 'ok';
        });

        $this->assertSame('ok', $result);
    }
}
