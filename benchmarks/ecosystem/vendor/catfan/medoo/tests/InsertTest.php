<?php

namespace Medoo\Tests;

use Medoo\Medoo;

#[\PHPUnit\Framework\Attributes\CoversClass(\Medoo\Medoo::class)]
class InsertTest extends MedooTestCase
{
    #[\PHPUnit\Framework\Attributes\DataProviderExternal(MedooTestCase::class, 'typesProvider')]
    public function testInsert(string $type): void
    {
        $this->setType($type);

        $this->database->insert("account", [
            "user_name" => "foo",
            "email" => "foo@bar.com"
        ]);

        $this->assertQuery(
            <<<EOD
            INSERT INTO "account" ("user_name", "email")
            VALUES ('foo', 'foo@bar.com')
            EOD,
            $this->database->queryString
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProviderExternal(MedooTestCase::class, 'typesProvider')]
    public function testInsertWithArray(string $type): void
    {
        $this->setType($type);

        $this->database->insert("account", [
            "user_name" => "foo",
            "lang" => ["en", "fr"]
        ]);

        $this->assertQuery([
            'default' => <<<EOD
                INSERT INTO "account" ("user_name", "lang")
                VALUES ('foo', 'a:2:{i:0;s:2:"en";i:1;s:2:"fr";}')
                EOD,
            'mysql' => <<<EOD
                INSERT INTO "account" ("user_name", "lang")
                VALUES ('foo', 'a:2:{i:0;s:2:\"en\";i:1;s:2:\"fr\";}')
                EOD
        ], $this->database->queryString);
    }

    #[\PHPUnit\Framework\Attributes\DataProviderExternal(MedooTestCase::class, 'typesProvider')]
    public function testInsertWithJSON(string $type): void
    {
        $this->setType($type);

        $this->database->insert("account", [
            "user_name" => "foo",
            "lang [JSON]" => ["en", "fr"]
        ]);

        $this->assertQuery([
            'default' => <<<EOD
                INSERT INTO "account" ("user_name", "lang")
                VALUES ('foo', '["en","fr"]')
                EOD,
            'mysql' => <<<EOD
                INSERT INTO `account` (`user_name`, `lang`)
                VALUES ('foo', '[\"en\",\"fr\"]')
                EOD
        ], $this->database->queryString);
    }

    #[\PHPUnit\Framework\Attributes\DataProviderExternal(MedooTestCase::class, 'typesProvider')]
    public function testInsertWithRaw(string $type): void
    {
        $this->setType($type);

        $this->database->insert("account", [
            "user_name" => Medoo::raw("UUID()")
        ]);

        $this->assertQuery(
            <<<EOD
            INSERT INTO "account" ("user_name")
            VALUES (UUID())
            EOD,
            $this->database->queryString
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProviderExternal(MedooTestCase::class, 'typesProvider')]
    public function testInsertWithNull(string $type): void
    {
        $this->setType($type);

        $this->database->insert("account", [
            "location" => null
        ]);

        $this->assertQuery(
            <<<EOD
            INSERT INTO "account" ("location")
            VALUES (NULL)
            EOD,
            $this->database->queryString
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProviderExternal(MedooTestCase::class, 'typesProvider')]
    public function testInsertWithObject(string $type): void
    {
        $this->setType($type);

        $objectData = new Foo();

        $this->database->insert("account", [
            "object" => $objectData
        ]);

        $this->assertQuery(
            <<<EOD
            INSERT INTO "account" ("object")
            VALUES (:MeD0_mK)
            EOD,
            $this->database->queryString
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProviderExternal(MedooTestCase::class, 'typesProvider')]
    public function testMultiInsert(string $type): void
    {
        $this->setType($type);

        $this->database->insert("account", [
            [
                "user_name" => "foo",
                "email" => "foo@bar.com"
            ],
            [
                "user_name" => "bar",
                "email" => "bar@foo.com"
            ]
        ]);

        $this->assertQuery(
            <<<EOD
            INSERT INTO "account" ("user_name", "email")
            VALUES ('foo', 'foo@bar.com'), ('bar', 'bar@foo.com')
            EOD,
            $this->database->queryString
        );
    }

    public function testOracleWithPrimaryKeyInsert(): void
    {
        $this->setType("oracle");

        $this->database->insert("ACCOUNT", [
            "NAME" => "foo",
            "EMAIL" => "foo@bar.com"
        ], "ID");

        $this->assertQuery(
            <<<EOD
            INSERT INTO "ACCOUNT" ("NAME", "EMAIL")
            VALUES ('foo', 'foo@bar.com')
            RETURNING "ID" INTO :RETURNID
            EOD,
            $this->database->queryString
        );
    }

    public function testPostgreSQLWithPrimaryKeyInsert(): void
    {
        $this->setType("pgsql");

        $this->database->insert("account", [
            "name" => "foo",
            "email" => "foo@bar.com"
        ], "id");

        $this->assertQuery(
            <<<EOD
            INSERT INTO "account" ("name", "email")
            VALUES ('foo', 'foo@bar.com')
            RETURNING "id"
            EOD,
            $this->database->queryString
        );
    }

    public function testOracleWithLOBsInsert(): void
    {
        $this->setType("oracle");

        $fp = fopen('README.md', 'r');

        $this->database->insert("ACCOUNT", [
            "NAME" => "foo",
            "DATA" => $fp
        ]);

        $this->assertQuery(
            <<<EOD
            INSERT INTO "ACCOUNT" ("NAME", "DATA")
            VALUES ('foo', EMPTY_BLOB())
            RETURNING "DATA" INTO :MeD1_mK
            EOD,
            $this->database->queryString
        );
    }

    public function testOracleWithLOBsAndIdInsert(): void
    {
        $this->setType("oracle");

        $fp = fopen('README.md', 'r');

        $this->database->insert("ACCOUNT", [
            "NAME" => "foo",
            "DATA" => $fp
        ], "ID");

        $this->assertQuery(
            <<<EOD
            INSERT INTO "ACCOUNT" ("NAME", "DATA")
            VALUES ('foo', EMPTY_BLOB())
            RETURNING "DATA", "ID" INTO :MeD1_mK, :RETURNID
            EOD,
            $this->database->queryString
        );
    }
}
