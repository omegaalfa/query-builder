<?php

namespace Medoo\Tests;

use PDO;
use PDOException;
use PDOStatement;

#[\PHPUnit\Framework\Attributes\CoversClass(\Medoo\Medoo::class)]
class IdTest extends MedooTestCase
{
    public function testOracleIdReturnsCachedValue(): void
    {
        $this->setType('oracle');
        $this->database->returnId = '42';

        $this->assertSame('42', $this->database->id());
    }

    public function testIdReturnsNullWithoutConnection(): void
    {
        $this->setType('mysql');

        $this->assertNull($this->database->id());
    }

    public function testPostgreSQLIdUsesLastvalWithoutSequenceName(): void
    {
        $this->setType('pgsql');
        $pdo = new IdTestPDO('12', '34');
        $this->database->pdo = $pdo;

        $this->assertSame('34', $this->database->id());
        $this->assertSame(['SELECT LASTVAL()'], $pdo->queries);
        $this->assertSame([], $pdo->lastInsertIdNames);
    }

    public function testPostgreSQLIdUsesCachedReturningValue(): void
    {
        $this->setType('pgsql');
        $pdo = new IdTestPDO('12', '34');
        $this->database->pdo = $pdo;
        $this->database->returnId = '42';

        $this->assertSame('42', $this->database->id());
        $this->assertSame([], $pdo->queries);
        $this->assertSame([], $pdo->lastInsertIdNames);
    }

    public function testPostgreSQLIdReturnsNullBeforeSequenceUse(): void
    {
        $this->setType('pgsql');
        $this->database->pdo = new IdTestPDO('12', new PDOException('lastval is not yet defined'));

        $this->assertNull($this->database->id());
    }

    public function testPostgreSQLIdUsesSequenceName(): void
    {
        $this->setType('pgsql');
        $pdo = new IdTestPDO('56', '78');
        $this->database->pdo = $pdo;

        $this->assertSame('56', $this->database->id('account_id_seq'));
        $this->assertSame([], $pdo->queries);
        $this->assertSame(['account_id_seq'], $pdo->lastInsertIdNames);
    }

    public function testPostgreSQLIdKeepsZeroValue(): void
    {
        $this->setType('pgsql');
        $this->database->pdo = new IdTestPDO('12', '0');

        $this->assertSame('0', $this->database->id());
    }

    public function testSybaseIdUsesIdentity(): void
    {
        $this->setType('sybase');
        $pdo = new IdTestPDO('12', '90');
        $this->database->pdo = $pdo;

        $this->assertSame('90', $this->database->id());
        $this->assertSame(['SELECT @@IDENTITY'], $pdo->queries);
        $this->assertSame([], $pdo->lastInsertIdNames);
    }

    public function testDriverFalseIdReturnsNull(): void
    {
        $this->setType('mysql');
        $this->database->pdo = new IdTestPDO(false, '12');

        $this->assertNull($this->database->id());
    }

    public function testDefaultDriverIdUsesPdoLastInsertId(): void
    {
        $this->setType('mysql');
        $pdo = new IdTestPDO('123', '12');
        $this->database->pdo = $pdo;

        $this->assertSame('123', $this->database->id());
        $this->assertSame([], $pdo->queries);
        $this->assertSame([null], $pdo->lastInsertIdNames);
    }
}

class IdTestPDO extends PDO
{
    /** @var list<string> */
    public array $queries = [];

    /** @var list<string|null> */
    public array $lastInsertIdNames = [];

    protected string|false $lastInsertId;
    protected string|false|PDOException $queryResult;

    public function __construct(string|false $lastInsertId, string|false|PDOException $queryResult)
    {
        $this->lastInsertId = $lastInsertId;
        $this->queryResult = $queryResult;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        $this->lastInsertIdNames[] = $name;

        return $this->lastInsertId;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->queries[] = $query;

        if ($this->queryResult instanceof PDOException) {
            $this->queryResult->errorInfo = ['55000', 0, $this->queryResult->getMessage()];
            throw $this->queryResult;
        }

        return new IdTestStatement($this->queryResult);
    }
}

class IdTestStatement extends PDOStatement
{
    protected string|false $value;

    public function __construct(string|false $value)
    {
        $this->value = $value;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->value;
    }
}
