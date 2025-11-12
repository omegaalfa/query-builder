<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\QueryBuilder;
use Omegaalfa\QueryBuilder\exceptions\DatabaseException;
use Omegaalfa\QueryBuilder\interfaces\ConnectionInterface;
use Omegaalfa\QueryBuilder\interfaces\PaginatorInterface;
use Omegaalfa\QueryBuilder\PaginationDTO;
use Omegaalfa\QueryBuilder\enums\SqlOperator;
use PHPUnit\Framework\TestCase;
use PDO;
use PDOStatement;

final class QueryBuilderBindingTest extends TestCase
{
    private function makePaginator(): PaginatorInterface
    {
        return new class implements PaginatorInterface {
            public function paginate(int $total, int $perPage, int $currentPage): PaginationDTO
            {
                return new PaginationDTO($currentPage, $perPage, 1, $total);
            }
        };
    }

    public function testBindNullUsesParamNull(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('bindValue')->with(':param0', null, PDO::PARAM_NULL);
        $stmt->expects($this->once())->method('execute')->willReturn(true);
        $stmt->expects($this->any())->method('rowCount')->willReturn(0);

        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->any())->method('prepare')->willReturn($stmt);

    $connection = $this->createMock(ConnectionInterface::class);
    $connection->expects($this->any())->method('pdo')->willReturn($pdo);
    $connection->expects($this->any())->method('getDriver')->willReturn('mysql');

        $qb = new QueryBuilder($connection, $this->makePaginator(), null, null);
    $result = $qb->select('t')->where('col', SqlOperator::EQUALS, null)->execute();

        $this->assertNotNull($result);
    }

    public function testBindIntUsesParamInt(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('bindValue')->with(':param0', 123, PDO::PARAM_INT);
        $stmt->expects($this->once())->method('execute')->willReturn(true);
        $stmt->expects($this->any())->method('rowCount')->willReturn(0);

        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->any())->method('prepare')->willReturn($stmt);

    $connection = $this->createMock(ConnectionInterface::class);
    $connection->expects($this->any())->method('pdo')->willReturn($pdo);
    $connection->expects($this->any())->method('getDriver')->willReturn('mysql');

        $qb = new QueryBuilder($connection, $this->makePaginator(), null, null);
    $qb->select('t')->where('col', SqlOperator::EQUALS, 123);
        $res = $qb->execute();

        $this->assertNotNull($res);
    }

    public function testBindBoolCastsToIntParam(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('bindValue')->with(':param0', 1, PDO::PARAM_INT);
        $stmt->expects($this->once())->method('execute')->willReturn(true);

        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->any())->method('prepare')->willReturn($stmt);

    $connection = $this->createMock(ConnectionInterface::class);
    $connection->expects($this->any())->method('pdo')->willReturn($pdo);
    $connection->expects($this->any())->method('getDriver')->willReturn('mysql');

        $qb = new QueryBuilder($connection, $this->makePaginator(), null, null);
    $qb->select('t')->where('col', SqlOperator::EQUALS, true);
        $this->assertNotNull($qb->execute());
    }

    public function testBindDateTimeFormatsString(): void
    {
        $dt = new \DateTimeImmutable('2020-01-02 03:04:05');

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('bindValue')->with(':param0', $dt->format('Y-m-d H:i:s'));
        $stmt->expects($this->once())->method('execute')->willReturn(true);

        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->any())->method('prepare')->willReturn($stmt);

    $connection = $this->createMock(ConnectionInterface::class);
    $connection->expects($this->any())->method('pdo')->willReturn($pdo);
    $connection->expects($this->any())->method('getDriver')->willReturn('mysql');

        $qb = new QueryBuilder($connection, $this->makePaginator(), null, null);
    $qb->select('t')->where('col', SqlOperator::EQUALS, $dt);
        $this->assertNotNull($qb->execute());
    }

    public function testBindResourceUsesBindParamLob(): void
    {
        $resource = fopen('php://memory', 'r');

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('bindParam')->with(':param0', $resource, PDO::PARAM_LOB);
        $stmt->expects($this->once())->method('execute')->willReturn(true);

        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->any())->method('prepare')->willReturn($stmt);

    $connection = $this->createMock(ConnectionInterface::class);
    $connection->expects($this->any())->method('pdo')->willReturn($pdo);
    $connection->expects($this->any())->method('getDriver')->willReturn('mysql');

        $qb = new QueryBuilder($connection, $this->makePaginator(), null, null);
    $qb->select('t')->where('col', SqlOperator::EQUALS, $resource);
        $this->assertNotNull($qb->execute());

        fclose($resource);
    }

    public function testArrayBindingThrowsDatabaseException(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->any())->method('prepare')->willReturn($stmt);

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->any())->method('pdo')->willReturn($pdo);
        $connection->expects($this->any())->method('getDriver')->willReturn('sqlite');

        $qb = new QueryBuilder($connection, $this->makePaginator(), null, null);

    $this->expectException(DatabaseException::class);
    $qb->select('t')->where('col', SqlOperator::EQUALS, [1,2,3])->execute();
    }
}
