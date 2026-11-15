<?php

namespace Medoo\Tests;

#[\PHPUnit\Framework\Attributes\CoversClass(\Medoo\Medoo::class)]
class AggregateTest extends MedooTestCase
{
    #[\PHPUnit\Framework\Attributes\DataProviderExternal(MedooTestCase::class, 'typesProvider')]
    public function testCount(string $type): void
    {
        $this->setType($type);

        $this->database->count("account", [
            "gender" => "female"
        ]);

        $this->assertQuery(
            <<<EOD
            SELECT COUNT(*)
            FROM "account"
            WHERE "gender" = 'female'
            EOD,
            $this->database->queryString
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProviderExternal(MedooTestCase::class, 'typesProvider')]
    public function testMax(string $type): void
    {
        $this->setType($type);

        $this->database->max("account", "age");

        $this->assertQuery(
            <<<EOD
            SELECT MAX("age")
            FROM "account"
            EOD,
            $this->database->queryString
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProviderExternal(MedooTestCase::class, 'typesProvider')]
    public function testMin(string $type): void
    {
        $this->setType($type);

        $this->database->min("account", "age");

        $this->assertQuery(
            <<<EOD
            SELECT MIN("age")
            FROM "account"
            EOD,
            $this->database->queryString
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProviderExternal(MedooTestCase::class, 'typesProvider')]
    public function testAvg(string $type): void
    {
        $this->setType($type);

        $this->database->avg("account", "age");

        $this->assertQuery(
            <<<EOD
            SELECT AVG("age")
            FROM "account"
            EOD,
            $this->database->queryString
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProviderExternal(MedooTestCase::class, 'typesProvider')]
    public function testSum(string $type): void
    {
        $this->setType($type);

        $this->database->sum("account", "money");

        $this->assertQuery(
            <<<EOD
            SELECT SUM("money")
            FROM "account"
            EOD,
            $this->database->queryString
        );
    }
}
