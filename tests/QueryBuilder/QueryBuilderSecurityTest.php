<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\QueryBuilderOperations;
use PHPUnit\Framework\TestCase;

/**
 * Testes para demonstrar como campos passados a select() são refletidos
 * diretamente em getQuerySql() (útil para validar riscos de injeção
 * quando campos forem construídos a partir de entrada externa).
 */
final class QueryBuilderSecurityTest extends TestCase
{
    public function testSelectIncludesMaliciousSemicolonField(): void
    {
        $qb = new QueryBuilderOperations();
        $sql = $qb->select('users', ['id; DROP TABLE users;'])->getQuerySql();

        $this->assertStringContainsString('id; DROP TABLE users;', $sql);
    }

    public function testSelectIncludesMaliciousCommentField(): void
    {
        $qb = new QueryBuilderOperations();
        $sql = $qb->select('users', ["name) --"])->getQuerySql();

        $this->assertStringContainsString('name) --', $sql);
    }

    public function testSelectIncludesFunctionExpression(): void
    {
        $qb = new QueryBuilderOperations();
        $sql = $qb->select('orders', ['status', 'COUNT(*) AS total'])->getQuerySql();

        $this->assertStringContainsString('COUNT(*) AS total', $sql);
    }

    public function testSelectQualifiedIdentifierLeftIntact(): void
    {
        $qb = new QueryBuilderOperations();
        $sql = $qb->select('prod', ['p.id', 'p.name'])->getQuerySql();

        $this->assertStringContainsString('p.id', $sql);
        $this->assertStringContainsString('p.name', $sql);
    }
}
