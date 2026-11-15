<?php

namespace Medoo\Tests;

use Medoo\Medoo;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

#[\PHPUnit\Framework\Attributes\CoversClass(\Medoo\Medoo::class)]
class ConnectionTest extends MedooTestCase
{
    public function testPostgreSQLAppliesValidatedCharsetToInjectedPdo(): void
    {
        $pdo = new ConnectionTestPDO();

        new Medoo([
            'type' => 'pgsql',
            'pdo' => $pdo,
            'charset' => 'UTF-8'
        ]);

        $this->assertSame(["SET NAMES 'UTF-8'"], $pdo->executedStatements);
    }

    #[DataProvider('invalidEncodingNamesProvider')]
    public function testPostgreSQLRejectsInvalidCharset(mixed $charset): void
    {
        $pdo = new ConnectionTestPDO();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid charset supplied.');

        new Medoo([
            'type' => 'pgsql',
            'pdo' => $pdo,
            'charset' => $charset
        ]);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidEncodingNamesProvider(): array
    {
        return [
            'empty' => [''],
            'leading digit' => ['8UTF'],
            'space' => ['UTF 8'],
            'quote' => ["UTF8'"],
            'statement delimiter' => ['UTF8;SELECT'],
            'non-string' => [123]
        ];
    }

    public function testMySQLRejectsInvalidCollation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid collation supplied.');

        new Medoo([
            'type' => 'mysql',
            'pdo' => new ConnectionTestPDO(),
            'charset' => 'utf8mb4',
            'collation' => "utf8mb4_general_ci'"
        ]);
    }
}

class ConnectionTestPDO extends PDO
{
    /** @var list<string> */
    public array $executedStatements = [];

    public function __construct()
    {
    }

    public function exec(string $statement): int|false
    {
        $this->executedStatements[] = $statement;

        return 0;
    }
}
