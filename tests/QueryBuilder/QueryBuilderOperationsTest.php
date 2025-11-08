<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\enums\JoinType;
use Omegaalfa\QueryBuilder\enums\OrderDirection;
use Omegaalfa\QueryBuilder\enums\SqlOperator;
use Omegaalfa\QueryBuilder\exceptions\QueryException;
use Omegaalfa\QueryBuilder\QueryBuilderOperations;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \Omegaalfa\QueryBuilder\QueryBuilderOperations
 */
final class QueryBuilderOperationsTest extends TestCase
{
    private QueryBuilderOperations $qb;

    public function testSelectWithAlias(): void
    {
        $sql = $this->qb->select('doenca')->alias('d')->getQuerySql();
        $this->assertStringContainsString('FROM `doenca` AS `d`', $sql);
    }

    // ---------------------------------------------------------------------
    // SELECT / ALIAS
    // ---------------------------------------------------------------------

    public function testSelectWithFields(): void
    {
        $sql = $this->qb->select('doenca', ['idDoenca', 'nome'])->getQuerySql();
        $this->assertSame('SELECT idDoenca, nome FROM `doenca`', $sql);
    }

    public function testInsertGeneratesExpectedSQL(): void
    {
        $sql = $this->qb->insert('doenca', ['nome' => 'A', 'status' => 1])->getQuerySql();
        $this->assertStringContainsString('INSERT INTO `doenca`', $sql);
        $this->assertStringContainsString('(nome, status)', $sql);
        $this->assertStringContainsString('VALUES (:nome, :status)', $sql);
    }

    // ---------------------------------------------------------------------
    // INSERT / UPDATE / DELETE
    // ---------------------------------------------------------------------

    public function testUpdateGeneratesExpectedSQL(): void
    {
        $sql = $this->qb->update('doenca', ['nome' => 'X'])->getQuerySql();
        $this->assertSame('UPDATE `doenca` SET `nome` = :nome', $sql);
    }

    public function testDeleteGeneratesExpectedSQL(): void
    {
        $sql = $this->qb->delete('doenca')->getQuerySql();
        $this->assertSame('DELETE FROM `doenca`', $sql);
    }

    public function testWhereEquals(): void
    {
        $sql = $this->qb->select('doenca')->where('status', SqlOperator::EQUALS, 1)->getQuerySql();
        $this->assertStringContainsString('WHERE `status` = :param0', $sql);
    }

    // ---------------------------------------------------------------------
    // WHERE CLAUSES
    // ---------------------------------------------------------------------

    public function testOrWhere(): void
    {
        $sql = $this->qb->select('doenca')
            ->where('status', SqlOperator::EQUALS, 1)
            ->orWhere('nome', SqlOperator::LIKE, '%botulismo%')
            ->getQuerySql();

        $this->assertStringContainsString('(`status` = :param0 OR `nome` LIKE :param1)', $sql);
    }

    public function testWhereIn(): void
    {
        $sql = $this->qb->select('doenca')->whereIn('status', [1, 2])->getQuerySql();
        $this->assertStringContainsString('`status` IN (:status_in_0, :status_in_1)', $sql);
    }

    public function testWhereNotIn(): void
    {
        $sql = $this->qb->select('doenca')->whereNotIn('status', [1, 2])->getQuerySql();
        $this->assertStringContainsString('`status` NOT IN (:status_notin_0, :status_notin_1)', $sql);
    }

    public function testWhereBetween(): void
    {
        $sql = $this->qb->select('doenca')->whereBetween('idDoenca', [1, 10])->getQuerySql();
        $this->assertStringContainsString('`idDoenca` BETWEEN :idDoenca_bt1 AND :idDoenca_bt2', $sql);
    }

    public function testWhereNotBetween(): void
    {
        $sql = $this->qb->select('doenca')->whereNotBetween('idDoenca', [1, 10])->getQuerySql();
        $this->assertStringContainsString('`idDoenca` NOT BETWEEN :idDoenca_nbt1 AND :idDoenca_nbt2', $sql);
    }

    public function testWhereNullAndNotNull(): void
    {
        $sql = $this->qb->select('doenca')->whereNull('acessos')->getQuerySql();
        $this->assertStringContainsString('`acessos` IS NULL', $sql);

        $sql2 = $this->qb->select('doenca')->whereNotNull('nome')->getQuerySql();
        $this->assertStringContainsString('`nome` IS NOT NULL', $sql2);
    }

    public function testJoin(): void
    {
        $qb = new QueryBuilderOperations();

        // LEFT JOIN
        $sql = $qb->select('doenca', ['d.idDoenca', 'd.nome'])
            ->alias('d')
            ->join('doenca_comercial', 'doenca_comercial.idDoenca', '=', 'd.idDoenca', JoinType::LEFT)
            ->getQuerySql();

        $this->assertStringContainsString(
            'LEFT JOIN `doenca_comercial` ON `doenca_comercial`.`idDoenca` = `d`.`idDoenca`',
            $sql
        );

        // INNER JOIN
        $qb = new QueryBuilderOperations();
        $sql = $qb->select('pacientes', ['p.id', 'p.nome'])
            ->alias('p')
            ->join('consultas', 'p.id', '=', 'consultas.idPaciente', JoinType::INNER)
            ->getQuerySql();

        $this->assertStringContainsString(
            'INNER JOIN `consultas` ON `p`.`id` = `consultas`.`idPaciente`',
            $sql
        );

        // RIGHT JOIN
        $qb = new QueryBuilderOperations();
        $sql = $qb->select('exames', ['e.id', 'e.resultado'])
            ->alias('e')
            ->join('laboratorios', 'e.idLaboratorio', '=', 'laboratorios.id', JoinType::RIGHT)
            ->getQuerySql();

        $this->assertStringContainsString(
            'RIGHT JOIN `laboratorios` ON `e`.`idLaboratorio` = `laboratorios`.`id`',
            $sql
        );

        // Detecta o driver configurado
        $driverProperty = (new \ReflectionClass($qb))
            ->getProperty('driver');
        $driverProperty->setAccessible(true);
        $driver = $driverProperty->getValue($qb);

        // FULL JOIN (varia conforme o driver)
        $qb = new QueryBuilderOperations();
        $sql = $qb->select('tabela_a', ['a.id'])
            ->alias('a')
            ->join('tabela_b', 'a.id', '=', 'tabela_b.id', JoinType::FULL)
            ->getQuerySql();

        if ($driver === 'mysql') {
            // No MySQL o FULL JOIN é emulado com UNION
            $this->assertStringContainsString(
                'UNION',
                $sql,
                "No MySQL, o FULL JOIN deve ser emulado com UNION.\nRecebido:\n{$sql}"
            );
        } else {
            // Em outros drivers (Postgres, SQLite), FULL JOIN é nativo
            $this->assertStringContainsString(
                'FULL JOIN `tabela_b` ON `a`.`id` = `tabela_b`.`id`',
                $sql,
                "FULL JOIN esperado para driver {$driver}.\nRecebido:\n{$sql}"
            );
        }
    }



    // ---------------------------------------------------------------------
    // JOIN
    // ---------------------------------------------------------------------

    public function testOrderBy(): void
    {
        $sql = $this->qb->select('doenca')->orderBy('idDoenca', OrderDirection::DESC)->getQuerySql();
        $this->assertStringContainsString('ORDER BY `idDoenca` DESC', $sql);
    }

    // ---------------------------------------------------------------------
    // ORDER BY / GROUP BY / HAVING
    // ---------------------------------------------------------------------

    public function testGroupByAndHaving(): void
    {
        $sql = $this->qb
            ->select('doenca', ['status', 'COUNT(*) AS qtd'])
            ->groupBy('status')
            ->having('status', SqlOperator::GREATER_THAN, 0)
            ->getQuerySql();

        $this->assertStringContainsString('GROUP BY `status`', $sql);
        $this->assertStringContainsString('HAVING `status` > :having0', $sql);
    }

    public function testHavingRequiresGroupBy(): void
    {
        $this->expectException(QueryException::class);
        $this->qb->select('doenca')->having('status', SqlOperator::GREATER_THAN, 0);
    }

    public function testHavingRaw(): void
    {
        $sql = $this->qb
            ->select('doenca', ['status', 'COUNT(*) AS qtd'])
            ->groupBy('status')
            ->havingRaw('COUNT(*) > 1')
            ->getQuerySql();

        $this->assertStringContainsString('HAVING COUNT(*) > 1', $sql);
    }

    public function testLimit(): void
    {
        $sql = $this->qb->select('doenca')->limit(10, 5)->getQuerySql();
        $this->assertStringContainsString('LIMIT 5 , 10', $sql);
    }

    // ---------------------------------------------------------------------
    // LIMIT / RAW
    // ---------------------------------------------------------------------

    public function testRawQuery(): void
    {
        $sql = $this->qb->raw('SELECT * FROM doenca WHERE status = ?', [1])->getQuerySql();
        $this->assertSame('SELECT * FROM doenca WHERE status = ?', $sql);
    }

    public function testQuoteIdentifierEscapesProperly(): void
    {
        $reflection = new ReflectionClass($this->qb);
        $method = $reflection->getMethod('quoteIdentifier');
        $method->setAccessible(true);

        $result = $method->invoke($this->qb, 'tabela.coluna');
        $this->assertSame('`tabela`.`coluna`', $result);

        $result2 = $method->invoke($this->qb, 'tabela as t');
        $this->assertSame('`tabela` AS `t`', $result2);
    }

    // ---------------------------------------------------------------------
    // PROTEÇÃO DE IDENTIFICADORES
    // ---------------------------------------------------------------------

    protected function setUp(): void
    {
        $this->qb = new QueryBuilderOperations();
    }
}