<?php

namespace Medoo\Tests;

use Medoo\Medoo;
use PHPUnit\Framework\TestCase;

class MedooTestCase extends TestCase
{
    /** @var Medoo */
    protected $database;

    public string $tableAliasConnector = ' AS ';
    public string $quotePattern = '"$1"';

    public function setUp(): void
    {
        $this->database = new Medoo([
            'testMode' => true
        ]);
    }

    /** @return array<string, array{string}> */
    public static function typesProvider(): array
    {
        return [
            'MySQL' => ['mysql'],
            'MSSQL' => ['mssql'],
            'SQLite' => ['sqlite'],
            'PostgreSQL' => ['pgsql'],
            'Oracle' => ['oracle']
        ];
    }

    public function setType(string $type): void
    {
        $this->database->setupType($type);
        $databaseType = $this->database->type;

        if ($databaseType === 'oracle') {
            $this->tableAliasConnector = ' ';
        } elseif ($databaseType === 'mysql') {
            $this->quotePattern = '`$1`';
        } elseif ($databaseType === 'mssql') {
            $this->quotePattern = '[$1]';
        }
    }

    public function expectedQuery(string $expected): string
    {
        $result = preg_replace(
            '/(?!\'[^\s]+\s?)"([\p{L}_][\p{L}\p{N}@$#\-_]*)"(?!\s?[^\s]+\')/u',
            $this->quotePattern,
            str_replace("\n", " ", $expected)
        );

        if ($result === null) {
            return $expected;
        }

        return str_replace(
            ' @AS ',
            $this->tableAliasConnector,
            $result
        );
    }

    /** @param array<string, string>|string $expected */
    public function assertQuery($expected, ?string $query): void
    {
        if (is_array($expected)) {
            $type = $this->database->type;
            $expectedQuery = is_string($type) && isset($expected[$type]) ? $expected[$type] : $expected['default'];

            $this->assertEquals(
                $this->expectedQuery($expectedQuery),
                $query
            );
        } else {
            $this->assertEquals($this->expectedQuery($expected), $query);
        }
    }
}

class Foo
{
    public string $bar = "cat";

    public function __wakeup(): void
    {
        $this->bar = "dog";
    }
}
