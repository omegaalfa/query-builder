<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\enums\OrderDirection;
use Omegaalfa\QueryBuilder\enums\SqlOperator;
use Omegaalfa\QueryBuilder\exceptions\QueryException;
use Omegaalfa\QueryBuilder\QueryBuilderOperations;
use PHPUnit\Framework\TestCase;

class QueryBuilderOperationsTest extends TestCase
{
    public function testSelect()
    {
        $qb = new QueryBuilderOperations();
        $qb->select('users', ['id', 'name']);
        $this->assertStringContainsString('SELECT id, name FROM users', $qb->getQuerySql());
    }

    public function testInsert()
    {
        $qb = new QueryBuilderOperations();
        $qb->insert('users', ['name' => 'John']);
        $this->assertStringContainsString('INSERT INTO users', $qb->getQuerySql());
    }

    public function testUpdate()
    {
        $qb = new QueryBuilderOperations();
        $qb->update('users', ['email' => 'john@example.com']);
        $this->assertStringContainsString('UPDATE users SET email = :email', $qb->getQuerySql());
    }

    public function testDelete()
    {
        $qb = new QueryBuilderOperations();
        $qb->delete('users');
        $this->assertStringContainsString('DELETE FROM users', $qb->getQuerySql());
    }

    public function testAlias()
    {
        $qb = new QueryBuilderOperations();
        $qb->select('users')->alias('u');
        $this->assertStringContainsString('AS u', $qb->getQuerySql());
    }

    public function testWhere()
    {
        $qb = new QueryBuilderOperations();
        $qb->select('users')->where('id', SqlOperator::EQUALS, 1);
        $this->assertStringContainsString('WHERE id = :param0', $qb->getQuerySql());
    }

    public function testOrWhere()
    {
        $qb = new QueryBuilderOperations();
        $qb->select('users')->where('id', SqlOperator::EQUALS, 1)->orWhere('name', SqlOperator::LIKE, 'A%');
        $this->assertStringContainsString('OR name LIKE :param1', $qb->getQuerySql());
    }

    public function testWhereIn()
    {
        $qb = new QueryBuilderOperations();
        $qb->select('users')->whereIn('id', [1, 2]);
        $this->assertStringContainsString('id IN (:id_in_0, :id_in_1)', $qb->getQuerySql());
    }

    public function testWhereNotIn()
    {
        $qb = new QueryBuilderOperations();
        $qb->select('users')->whereNotIn('id', [1, 2]);
        $this->assertStringContainsString('id NOT IN (:id_notin_0, :id_notin_1)', $qb->getQuerySql());
    }

    public function testWhereBetween()
    {
        $qb = new QueryBuilderOperations();
        $qb->select('users')->whereBetween('age', [18, 30]);
        $this->assertStringContainsString('age BETWEEN :age_bt1 AND :age_bt2', $qb->getQuerySql());
    }

    public function testWhereNotBetween()
    {
        $qb = new QueryBuilderOperations();
        $qb->select('users')->whereNotBetween('age', [18, 30]);
        $this->assertStringContainsString('age NOT BETWEEN :age_nbt1 AND :age_nbt2', $qb->getQuerySql());
    }

    public function testWhereNull()
    {
        $qb = new QueryBuilderOperations();
        $qb->select('users')->whereNull('deleted_at');
        $this->assertStringContainsString('deleted_at IS NULL', $qb->getQuerySql());
    }

    public function testWhereNotNull()
    {
        $qb = new QueryBuilderOperations();
        $qb->select('users')->whereNotNull('deleted_at');
        $this->assertStringContainsString('deleted_at IS NOT NULL', $qb->getQuerySql());
    }

    public function testJoin()
    {
        $qb = new QueryBuilderOperations();
        $qb->select('users')->join('posts', 'users.id', '=', 'posts.user_id');
        $this->assertStringContainsString('INNER JOIN posts ON users.id = posts.user_id', $qb->getQuerySql());
    }

    public function testOrderBy()
    {
        $qb = new QueryBuilderOperations();
        $qb->select('users')->orderBy('name', OrderDirection::DESC);
        $this->assertStringContainsString('ORDER BY name DESC', $qb->getQuerySql());
    }

    public function testGroupBy()
    {
        $qb = new QueryBuilderOperations();
        $qb->select('users')->groupBy('role');
        $this->assertStringContainsString('GROUP BY role', $qb->getQuerySql());
    }

    public function testHaving()
    {
        $qb = new QueryBuilderOperations();
        $qb->select('users')->groupBy('role')->having('count', SqlOperator::GREATER_THAN, 10);
        $this->assertStringContainsString('HAVING count > :having0', $qb->getQuerySql());
    }

    public function testHavingWithoutGroupByThrows()
    {
        $this->expectException(QueryException::class);
        $qb = new QueryBuilderOperations();
        $qb->select('users')->having('count', SqlOperator::GREATER_THAN, 10);
    }

    public function testLimit()
    {
        $qb = new QueryBuilderOperations();
        $qb->select('users')->limit(10, 5);
        $this->assertStringContainsString('LIMIT 5 , 10', $qb->getQuerySql());
    }

    public function testRaw()
    {
        $qb = new QueryBuilderOperations();
        $qb->raw('SELECT * FROM users WHERE active = 1');
        $this->assertSame('SELECT * FROM users WHERE active = 1', $qb->getQuerySql());
    }
}
