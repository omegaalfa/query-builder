<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\Enums\SqlOperator;
use Omegaalfa\QueryBuilder\Interfaces\ConnectionInterface;
use Omegaalfa\QueryBuilder\QueryBuilder;
use Omegaalfa\QueryBuilder\QueryBuilderOperations;
use Omegaalfa\QueryBuilder\Exceptions\QueryException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PDO;

#[AllowMockObjectsWithoutExpectations]
final class QueryBuilderRegressionTest extends TestCase
{
    public function testInsertQuotesColumnsAndUsesGeneratedPlaceholders(): void
    {
        $builder = new QueryBuilderOperations();

        $sql = $builder->insert('users', [
            'name' => 'Alice',
            'status) VALUES (1); DROP TABLE users; --' => 'x',
        ])->getQuerySql();

        self::assertStringContainsString('`name`', $sql);
        self::assertStringContainsString('`status) VALUES (1); DROP TABLE users; --`', $sql);
        self::assertStringNotContainsString('), VALUES (1); DROP TABLE', $sql);
        self::assertStringContainsString('VALUES (:param0, :param1)', $sql);
    }

    public function testRepeatedWhereInClausesDoNotCollide(): void
    {
        $builder = new QueryBuilderOperations();

        $sql = $builder->select('users')
            ->whereIn('status', [1, 2])
            ->whereIn('status', [3])
            ->getQuerySql();

        self::assertSame(
            'SELECT * FROM `users` WHERE `status` IN (:param0, :param1) AND `status` IN (:param2)',
            $sql,
        );
    }

    public function testWhereAcceptsEnumCaseNamesAsOperators(): void
    {
        $builder = new QueryBuilderOperations();

        $builder->select('users')->where('status', 'EQUALS', 1);

        self::assertStringContainsString('`status` = :param0', $builder->getQuerySql());
    }

    public function testWithBuildsCteAndRemapsSubqueryBindings(): void
    {
        $builder = new QueryBuilderOperations();

        $sql = $builder
            ->select('active_users')
            ->with('active_users', 'SELECT id FROM users WHERE status = ?', [1])
            ->getQuerySql();

        self::assertSame(
            'WITH `active_users` AS (SELECT id FROM users WHERE status = :param0) SELECT * FROM `active_users`',
            $sql,
        );
    }

    public function testWithAcceptsAConfiguredSubqueryBuilder(): void
    {
        $builder = new QueryBuilderOperations();

        $sql = $builder
            ->select('recent_users')
            ->with('recent_users', static function (QueryBuilderOperations $query): QueryBuilderOperations {
                return $query->select('users', ['id'])->where('created_at', '>', '2026-01-01');
            })
            ->getQuerySql();

        self::assertStringContainsString(
            'WITH `recent_users` AS (SELECT `id` FROM `users` WHERE `created_at` > :param0)',
            $sql,
        );
    }

    public function testWithRejectsUnsafeAliases(): void
    {
        $this->expectException(QueryException::class);

        (new QueryBuilderOperations())
            ->select('users')
            ->with('users; DROP TABLE users', 'SELECT 1');
    }

    public function testWhereExistsRemapsSubqueryBindings(): void
    {
        $builder = new QueryBuilderOperations();

        $sql = $builder
            ->select('users')
            ->whereExists('SELECT 1 FROM orders WHERE orders.user_id = users.id AND total > ?', [100])
            ->getQuerySql();

        self::assertSame(
            'SELECT * FROM `users` WHERE EXISTS (SELECT 1 FROM orders WHERE orders.user_id = users.id AND total > :param0)',
            $sql,
        );
    }

    public function testWhereInSubqueryAndFromSubqueryCompileSafely(): void
    {
        $inSql = (new QueryBuilderOperations())
            ->select('users')
            ->whereInSubquery('id', static function (QueryBuilderOperations $query): QueryBuilderOperations {
                return $query->select('orders', ['user_id'])->where('status', '=', 'paid');
            })
            ->getQuerySql();

        $fromSql = (new QueryBuilderOperations())
            ->fromSubquery('recent', 'SELECT id, name FROM users WHERE active = ?', [1])
            ->getQuerySql();

        self::assertStringContainsString(
            'WHERE `id` IN (SELECT `user_id` FROM `orders` WHERE `status` = :param0)',
            $inSql,
        );
        self::assertSame(
            'SELECT * FROM (SELECT id, name FROM users WHERE active = :param0) AS `recent`',
            $fromSql,
        );
    }

    public function testWhereNotExistsIsSupported(): void
    {
        $sql = (new QueryBuilderOperations())
            ->select('users')
            ->whereNotExists('SELECT 1 FROM bans WHERE bans.user_id = users.id')
            ->getQuerySql();

        self::assertStringContainsString('WHERE NOT EXISTS (SELECT 1 FROM bans WHERE bans.user_id = users.id)', $sql);
    }

    public function testCursorPaginationAddsOrderedLookaheadLimit(): void
    {
        $firstPage = (new QueryBuilderOperations())
            ->select('users')
            ->paginateCursor(2, null, 'id')
            ->getQuerySql();
        $nextPage = (new QueryBuilderOperations())
            ->select('users')
            ->paginateCursor(2, base64_encode('2'), 'id')
            ->getQuerySql();

        self::assertSame('SELECT * FROM `users` ORDER BY `id` ASC LIMIT 0 , 3', $firstPage);
        self::assertStringContainsString('WHERE `id` > :param0', $nextPage);
        self::assertStringContainsString('LIMIT 0 , 3', $nextPage);
    }

    public function testCursorPaginationRejectsInvalidCursorColumnAndCursor(): void
    {
        $this->expectException(QueryException::class);
        (new QueryBuilderOperations())->select('users')->paginateCursor(10, null, 'id; DROP TABLE users');
    }

    public function testCursorPaginationRejectsInvalidEncodedCursor(): void
    {
        $this->expectException(QueryException::class);
        (new QueryBuilderOperations())->select('users')->paginateCursor(10, 'not-a-cursor');
    }

    public function testRawPositionalAndNamedParametersAreNormalized(): void
    {
        $builder = new QueryBuilderOperations();

        self::assertSame(
            'SELECT * FROM users WHERE id = :param0 AND state = :param1',
            $builder->raw('SELECT * FROM users WHERE id = ? AND state = ?', [10, 1])->getQuerySql(),
        );
        self::assertSame(
            'SELECT * FROM users WHERE id = :param0 AND state = :param1',
            $builder->raw('SELECT * FROM users WHERE id = :id AND state = :state', ['id' => 10, 'state' => 1])->getQuerySql(),
        );
    }

    public function testHavingAcceptsAggregateExpressions(): void
    {
        $builder = new QueryBuilderOperations();

        self::assertSame(
            'SELECT `status` FROM `users` GROUP BY `status` HAVING COUNT(id) > :param0',
            $builder->select('users', ['status'])
                ->groupBy('status')
                ->having('COUNT(id)', SqlOperator::GREATER_THAN, 10)
                ->getQuerySql(),
        );
    }

    public function testSelectRawKeepsExpressionsExplicit(): void
    {
        $builder = new QueryBuilderOperations();

        self::assertSame(
            'SELECT COUNT(*) AS total FROM `users`',
            $builder->selectRaw('users', 'COUNT(*) AS total')->getQuerySql(),
        );
    }

    public function testWhereGroupCreatesAnAndGroup(): void
    {
        $builder = new QueryBuilderOperations();

        self::assertSame(
            'SELECT * FROM `users` WHERE `active` = :param0 AND (`role` = :param1 OR `role` = :param2)',
            $builder->select('users')
                ->where('active', '=', 1)
                ->whereGroup(static function (QueryBuilderOperations $query): void {
                    $query->where('role', '=', 'admin')->orWhere('role', '=', 'manager');
                })
                ->getQuerySql(),
        );
    }

    public function testAggregateMethodsRejectRawQueriesClearly(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('getDriver')->willReturn('sqlite');
        $builder = new QueryBuilder($connection);

        $this->expectExceptionMessage('Aggregate methods require a query created with select()');
        $builder->raw('SELECT * FROM users')->count();
    }

    public function testAggregateDoesNotMutateTheOriginalBuilder(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('SQLite PDO driver is not available.');
        }

        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, active INTEGER)');
        $pdo->exec('INSERT INTO users (active) VALUES (1), (1), (0)');

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('getDriver')->willReturn('sqlite');
        $connection->method('pdo')->willReturn($pdo);
        $builder = new QueryBuilder($connection);
        $builder->select('users')->where('active', '=', 1);
        $originalSql = $builder->getQuerySql();

        self::assertSame(2, $builder->count());
        self::assertSame($originalSql, $builder->getQuerySql());
    }
}
