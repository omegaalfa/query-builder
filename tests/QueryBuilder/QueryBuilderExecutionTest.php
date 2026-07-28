<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\PaginationDTO;
use Omegaalfa\QueryBuilder\QueryBuilder;
use Omegaalfa\QueryBuilder\QueryResultDTO;
use Omegaalfa\QueryBuilder\Interfaces\ConnectionInterface;
use Omegaalfa\QueryBuilder\Interfaces\PaginatorInterface;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class QueryBuilderExecutionTest extends TestCase
{
    public function testSumReturnsFloat(): void
    {
        $statement = $this->createStatementReturningRow(['total' => 42]);
        $qb = $this->buildQueryBuilderWithStatements([$statement]);

        $qb->select('doenca');
        $this->assertSame(42.0, $qb->sum('valor'));
    }

    public function testAvgReturnsAverage(): void
    {
        $statement = $this->createStatementReturningRow(['average' => 3.5]);
        $qb = $this->buildQueryBuilderWithStatements([$statement]);

        $qb->select('doenca');
        $this->assertSame(3.5, $qb->avg('valor'));
    }

    public function testMaxReturnsMaximum(): void
    {
        $statement = $this->createStatementReturningRow(['maximum' => 99]);
        $qb = $this->buildQueryBuilderWithStatements([$statement]);

        $qb->select('doenca');
        $this->assertSame(99, $qb->max('valor'));
    }

    public function testMinReturnsMinimum(): void
    {
        $statement = $this->createStatementReturningRow(['minimum' => 1]);
        $qb = $this->buildQueryBuilderWithStatements([$statement]);

        $qb->select('doenca');
        $this->assertSame(1, $qb->min('valor'));
    }

    public function testCountReturnsInt(): void
    {
        $statement = $this->createStatementReturningRow(['total' => 5]);
        $qb = $this->buildQueryBuilderWithStatements([$statement]);

        $qb->select('doenca');
        $this->assertSame(5, $qb->count());
    }

    public function testExistsReliesOnCount(): void
    {
        $statement = $this->createStatementReturningRow(['total' => 0]);
        $qb = $this->buildQueryBuilderWithStatements([$statement]);

        $qb->select('doenca');
        $this->assertFalse($qb->exists());
    }

    public function testLimitDoesNotCreatePagination(): void
    {
        $dataStatement = $this->createStatementReturningRow(['id' => 3]);
        $qb = $this->buildQueryBuilderWithStatements([$dataStatement], useDefaultPaginator: true);

        $result = $qb->select('doenca')->limit(2, 2)->execute();

        $this->assertNull($result->pagination);
    }

    public function testCreatesDefaultPaginatorWhenPaginateIsUsed(): void
    {
        $countStatement = $this->createStatementReturningRow(['total' => 5]);
        $dataStatement = $this->createStatementReturningRow(['id' => 3]);
        $qb = $this->buildQueryBuilderWithStatements(
            [$countStatement, $dataStatement],
            useDefaultPaginator: true,
        );

        $result = $qb->select('doenca')->paginate(perPage: 2, currentPage: 2)->execute();

        $this->assertEquals(
            new PaginationDTO(currentPage: 2, perPage: 2, totalPages: 3, totalItems: 5),
            $result->pagination,
        );
    }

    /**
     * @param array<array-key, PDOStatement> $statements
     */
    private function buildQueryBuilderWithStatements(
        array $statements,
        ?PaginatorInterface $paginator = null,
        bool $useDefaultPaginator = false,
    ): QueryBuilder
    {
        $pdo = new class($statements) extends PDO {
            /** @var PDOStatement[] */
            private array $statements;

            public function __construct(array $statements)
            {
                $this->statements = $statements;
            }

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                if (empty($this->statements)) {
                    throw new RuntimeException("No statement configured for {$query}");
                }

                return array_shift($this->statements);
            }

            public function lastInsertId($name = null): string|false
            {
                return '1';
            }
        };

        $connection = new class($pdo) implements ConnectionInterface {
            public function __construct(private PDO $pdo) {}
            public function connect(): void {}
            public function disconnect(): void {}
            public function pdo(bool $bufferedQuery = true): PDO { return $this->pdo; }
            public function transaction(callable $callback): mixed { return $callback($this->pdo); }
            public function getDriver(): string { return 'mysql'; }
        };

        if ($useDefaultPaginator) {
            return new QueryBuilder($connection);
        }

        return new QueryBuilder(
            $connection,
            $paginator ?? $this->createMock(PaginatorInterface::class),
        );
    }

    private function createStatementReturningRow(array $row): PDOStatement
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetch')->willReturnOnConsecutiveCalls($row, false);
        $statement->method('rowCount')->willReturn(1);
        $statement->method('closeCursor')->willReturn(true);

        return $statement;
    }
}
