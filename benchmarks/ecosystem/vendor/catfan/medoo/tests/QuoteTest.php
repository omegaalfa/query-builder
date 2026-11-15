<?php

namespace Medoo\Tests;

use Medoo\Medoo;
use InvalidArgumentException;

#[\PHPUnit\Framework\Attributes\CoversClass(\Medoo\Medoo::class)]
class QuoteTest extends MedooTestCase
{
    #[\PHPUnit\Framework\Attributes\DataProviderExternal(MedooTestCase::class, 'typesProvider')]
    public function testQuote(string $type): void
    {
        $this->setType($type);

        $quotedString = $this->database->quote("Co'mpl''ex \"st'\"ring");

        $expected = [
            'mysql' => <<<EOD
                'Co\'mpl\'\'ex \"st\'\"ring'
                EOD,
            'mssql' => <<<EOD
                'Co''mpl''''ex "st''"ring'
                EOD,
            'sqlite' => <<<EOD
                'Co''mpl''''ex "st''"ring'
                EOD,
            'pgsql' => <<<EOD
                'Co''mpl''''ex "st''"ring'
                EOD,
            'oracle' => <<<EOD
                'Co''mpl''''ex "st''"ring'
                EOD
        ];

        $this->assertEquals($expected[$type], $quotedString);
    }

    public function testColumnQuote(): void
    {
        $this->assertEquals('"ColumnName"', $this->database->columnQuote("ColumnName"));
        $this->assertEquals('"Column"."name"', $this->database->columnQuote("Column.name"));
        $this->assertEquals('"Column"."Name"', $this->database->columnQuote("Column.Name"));

        $this->assertEquals('"ネーム"', $this->database->columnQuote("ネーム"));
    }

    /** @return list<array{string}> */
    public static function columnNamesProvider(): array
    {
        return [
            ["9ColumnName"],
            ["@ColumnName"],
            [".ColumnName"],
            ["ColumnName."],
            ["ColumnName (alias)"]
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('columnNamesProvider')]
    public function testIncorrectColumnQuote(string $column): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->database->columnQuote($column);
    }

    public function testTableQuote(): void
    {
        $this->assertEquals('"TableName"', $this->database->tableQuote("TableName"));
        $this->assertEquals('"_table"', $this->database->tableQuote("_table"));

        $this->assertEquals('"アカウント"', $this->database->tableQuote("アカウント"));
    }

    public function testPrefixTableQuote(): void
    {
        $database = new Medoo([
            'testMode' => true,
            'prefix' => 'PREFIX_'
        ]);

        $this->assertEquals('"PREFIX_TableName"', $database->tableQuote("TableName"));
    }

    /** @return list<array{string}> */
    public static function tableNamesProvider(): array
    {
        return [
            ["9TableName"],
            ["@TableName"],
            [".TableName"],
            ["TableName."],
            ["Table.name"]
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tableNamesProvider')]
    public function testIncorrectTableQuote(string $table): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->database->tableQuote($table);
    }
}
