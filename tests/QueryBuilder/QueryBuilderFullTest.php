<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\enums\JoinType;
use Omegaalfa\QueryBuilder\enums\OrderDirection;
use Omegaalfa\QueryBuilder\enums\SqlOperator;
use Omegaalfa\QueryBuilder\exceptions\QueryException;
use Omegaalfa\QueryBuilder\interfaces\CacheInterface;
use Omegaalfa\QueryBuilder\interfaces\ConnectionInterface;
use Omegaalfa\QueryBuilder\interfaces\PaginatorInterface;
use Omegaalfa\QueryBuilder\QueryBuilder;
use Omegaalfa\QueryBuilder\QueryBuilderOperations;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class QueryBuilderFullTest extends TestCase
{
    private QueryBuilderOperations $qbOps;
    private QueryBuilder $qb;

    protected function setUp(): void
    {
        $this->qbOps = new QueryBuilderOperations();
        $mockConnection = $this->createMock(ConnectionInterface::class);
        $mockPaginator = $this->createMock(PaginatorInterface::class);
        $mockCache = $this->createMock(CacheInterface::class);
        $this->qb = new QueryBuilder($mockConnection, $mockPaginator, $mockCache);
    }

    public function testSelectInsertUpdateDeleteSQL(): void
    {
        $this->assertStringContainsString('SELECT id FROM `tabela`', $this->qbOps->select('tabela', ['id'])->getQuerySql());
        $this->assertStringContainsString('INSERT INTO `tabela`', $this->qbOps->insert('tabela', ['a' => 1])->getQuerySql());
        $this->assertStringContainsString('UPDATE `tabela` SET `a` = :a', $this->qbOps->update('tabela', ['a' => 1])->getQuerySql());
        $this->assertSame('DELETE FROM `tabela`', $this->qbOps->delete('tabela')->getQuerySql());
    }

    public function testInsertBatch(): void
    {
        $sql = $this->qbOps->insertBatch('doenca', [
            ['nome' => 'A', 'status' => 10],
            ['nome' => 'B', 'status' => 20]
        ])->getQuerySql();

        $this->assertStringContainsString('INSERT INTO `doenca` (`nome`, `status`) VALUES', $sql);
    }

    public function testAllWhereClauses(): void
    {
        $this->assertStringContainsString('IN', $this->qbOps->select('x')->whereIn('a', [1])->getQuerySql());
        $this->assertStringContainsString('NOT IN', $this->qbOps->select('x')->whereNotIn('a', [1])->getQuerySql());
        $this->assertStringContainsString('BETWEEN', $this->qbOps->select('x')->whereBetween('id', [1,2])->getQuerySql());
        $this->assertStringContainsString('NOT BETWEEN', $this->qbOps->select('x')->whereNotBetween('id', [1,2])->getQuerySql());
        $this->assertStringContainsString('IS NULL', $this->qbOps->select('x')->whereNull('a')->getQuerySql());
        $this->assertStringContainsString('IS NOT NULL', $this->qbOps->select('x')->whereNotNull('b')->getQuerySql());
    }

    public function testHavingClauses(): void
    {
        $this->assertStringContainsString('HAVING `a` > :having0', $this->qbOps
            ->select('x', ['a'])
            ->groupBy('a')
            ->having('a', SqlOperator::GREATER_THAN, 0)
            ->getQuerySql());

        $this->assertStringContainsString('HAVING COUNT(*) > 1', $this->qbOps
            ->select('x', ['a'])
            ->groupBy('a')
            ->havingRaw('COUNT(*) > 1')
            ->getQuerySql());
    }

    public function testJoinTypes(): void
    {
        $sql = $this->qbOps->select('a')
            ->alias('a')
            ->join('b', 'a.id', '=', 'b.id', JoinType::LEFT)
            ->getQuerySql();

        $this->assertStringContainsString('LEFT JOIN', $sql);
    }

    public function testFullJoinThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FULL JOIN não é suportado');

        $qb = new QueryBuilderOperations();
        $ref = new ReflectionClass($qb);
        $prop = $ref->getProperty('driver');
        $prop->setAccessible(true);
        $prop->setValue($qb, 'mysql');

        $qb->select('a')->join('b', 'a.id', '=', 'b.id', JoinType::FULL);
    }

    public function testAliasAndRaw(): void
    {
        $sql = $this->qbOps->select('tabela')->alias('t')->getQuerySql();
        $this->assertStringContainsString('AS `t`', $sql);

        $sql2 = $this->qbOps->raw('SELECT NOW()')->getQuerySql();
        $this->assertSame('SELECT NOW()', $sql2);
    }

    public function testQuoteIdentifier(): void
    {
        $ref = new ReflectionClass($this->qbOps);
        $method = $ref->getMethod('quoteIdentifier');
        $method->setAccessible(true);

        $this->assertSame('`tabela`.`coluna`', $method->invoke($this->qbOps, 'tabela.coluna'));
        $this->assertSame('`tabela` AS `t`', $method->invoke($this->qbOps, 'tabela as t'));
    }

    public function testResetStateDoesNotThrow(): void
    {
        $this->qbOps->select('x');
        $this->assertTrue(true); // não explode
    }

    public function testInsertBatchGeneratesCorrectSql()
    {
        $data = [
            ['name' => 'Alice', 'age' => 30],
            ['name' => 'Bob', 'age' => 25],
        ];

        $sql = $this->qb->insertBatch('users', $data)->getQuerySql();

        $this->assertStringContainsString('INSERT INTO `users`', $sql);
        $this->assertStringContainsString('(`name`, `age`)', $sql);
        $this->assertStringContainsString('VALUES (:name_0, :age_0), (:name_1, :age_1)', $sql);
    }

    public function testInsertBatchThrowsOnMismatchedColumns()
    {
        $this->expectException(\Omegaalfa\QueryBuilder\exceptions\QueryException::class);

        $data = [
            ['name' => 'Alice', 'age' => 30],
            ['name' => 'Bob'] // faltando a coluna 'age'
        ];

        $this->qb->insertBatch('users', $data);
    }

    public function testWhereNotInGeneratesCorrectSql()
    {
        $sql = $this->qb->select('products')->whereNotIn('id', [1, 2, 3])->getQuerySql();

        $this->assertStringContainsString('WHERE `id` NOT IN', $sql);
        $this->assertStringContainsString(':id_notin_0', $sql);
    }

    public function testWhereNotBetweenGeneratesCorrectSql()
    {
        $sql = $this->qb->select('sales')->whereNotBetween('amount', [100, 500])->getQuerySql();

        $this->assertStringContainsString('`amount` NOT BETWEEN', $sql);
        $this->assertStringContainsString(':amount_nbt1', $sql);
        $this->assertStringContainsString(':amount_nbt2', $sql);
    }

    public function testWhereNullGeneratesCorrectSql()
    {
        $sql = $this->qb->select('users')->whereNull('deleted_at')->getQuerySql();

        $this->assertStringContainsString('`deleted_at` IS NULL', $sql);
    }

    public function testWhereNotNullGeneratesCorrectSql()
    {
        $sql = $this->qb->select('users')->whereNotNull('email')->getQuerySql();

        $this->assertStringContainsString('`email` IS NOT NULL', $sql);
    }

    public function testInsertBatchSuccess(): void
    {
        $qb = new QueryBuilderOperations();
        $query = $qb->insertBatch('users', [
            ['name' => 'Alice', 'email' => 'alice@example.com'],
            ['name' => 'Bob', 'email' => 'bob@example.com']
        ])->getQuerySql();

        $this->assertStringContainsString('INSERT INTO `users`', $query);
        $this->assertStringContainsString('(`name`, `email`)', $query);
        $this->assertStringContainsString('VALUES (:name_0, :email_0), (:name_1, :email_1)', $query);
    }

    public function testInsertBatchFailsOnInconsistentColumns(): void
    {
        $this->expectException(QueryException::class);
        $qb = new QueryBuilderOperations();
        $qb->insertBatch('users', [
            ['name' => 'Alice', 'email' => 'alice@example.com'],
            ['name' => 'Bob']  // <- Faltando campo "email"
        ]);
    }

    public function testWhereNotIn(): void
    {
        $qb = new QueryBuilderOperations();
        $query = $qb->select('users')->whereNotIn('status', [0, 1])->getQuerySql();

        $this->assertStringContainsString('WHERE `status` NOT IN (:status_notin_0, :status_notin_1)', $query);
    }

    public function testWhereNotBetween(): void
    {
        $qb = new QueryBuilderOperations();
        $query = $qb->select('orders')->whereNotBetween('total', [100, 500])->getQuerySql();

        $this->assertStringContainsString('`total` NOT BETWEEN :total_nbt1 AND :total_nbt2', $query);
    }

    public function testWhereNull(): void
    {
        $qb = new QueryBuilderOperations();
        $query = $qb->select('products')->whereNull('description')->getQuerySql();

        $this->assertStringContainsString('`description` IS NULL', $query);
    }

    public function testWhereNotNull(): void
    {
        $qb = new QueryBuilderOperations();
        $query = $qb->select('products')->whereNotNull('price')->getQuerySql();

        $this->assertStringContainsString('`price` IS NOT NULL', $query);
    }

    public function testHavingClause(): void
    {
        $qb = new QueryBuilderOperations();
        $query = $qb->select('vendas', ['status', 'SUM(valor) AS total'])
            ->groupBy('status')
            ->having('total', SqlOperator::GREATER_THAN_OR_EQUALS, 1000)
            ->getQuerySql();

        $this->assertStringContainsString('HAVING `total` >= :having0', $query);
    }

    public function testHavingClauseWithoutGroupByThrows(): void
    {
        $this->expectException(QueryException::class);

        $qb = new QueryBuilderOperations();
        $qb->select('vendas')
            ->having('status', SqlOperator::EQUALS, 1)
            ->getQuerySql();
    }

    public function testHavingRawClause(): void
    {
        $qb = new QueryBuilderOperations();
        $query = $qb->select('pedidos', ['status', 'COUNT(*) as qtd'])
            ->groupBy('status')
            ->havingRaw('COUNT(*) > 10')
            ->getQuerySql();

        $this->assertStringContainsString('HAVING COUNT(*) > 10', $query);
    }

    public function testAliasSetsCorrectlyInFrom(): void
    {
        $qb = new QueryBuilderOperations();
        $sql = $qb->select('clientes')
            ->alias('c')
            ->getQuerySql();

        $this->assertStringContainsString('FROM `clientes` AS `c`', $sql);
    }

    public function testFullQueryWithAllClauses(): void
    {
        $qb = new QueryBuilderOperations();
        $sql = $qb->select('produtos', ['p.idProduto', 'p.nome', 'COUNT(v.idVenda) as totalVendas'])
            ->alias('p')
            ->join('vendas', 'p.idProduto', '=', 'vendas.idProduto', JoinType::LEFT)
            ->where('p.status', SqlOperator::EQUALS, 1)
            ->groupBy('p.idProduto')
            ->having('totalVendas', SqlOperator::GREATER_THAN, 5)
            ->orderBy('p.nome', OrderDirection::ASC)
            ->limit(10)
            ->getQuerySql();

        $this->assertStringContainsString('SELECT p.idProduto, p.nome, COUNT(v.idVenda) as totalVendas', $sql);
        $this->assertStringContainsString('FROM `produtos` AS `p`', $sql);
        $this->assertStringContainsString('LEFT JOIN `vendas` ON `p`.`idProduto` = `vendas`.`idProduto`', $sql);
        $this->assertStringContainsString('WHERE `p`.`status` = :param0', $sql);
        $this->assertStringContainsString('GROUP BY `p`.`idProduto`', $sql);
        $this->assertStringContainsString('HAVING `totalVendas` > :having1', $sql);
        $this->assertStringContainsString('ORDER BY `p`.`nome` ASC', $sql);
        $this->assertStringContainsString('LIMIT 0 , 10', $sql);
    }


}
