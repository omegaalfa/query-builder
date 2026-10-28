<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\DatabaseSettings;
use PHPUnit\Framework\TestCase;

final class DatabaseSettingsCharsetTest extends TestCase
{
    public function testCharsetDefaultsAreSelectedByDriver(): void
    {
        self::assertSame('utf8mb4', $this->settings('mysql')->charset);
        self::assertSame('UTF8', $this->settings('pgsql')->charset);
        self::assertSame('', $this->settings('sqlite')->charset);
    }

    public function testExplicitCharsetIsPreserved(): void
    {
        self::assertSame('LATIN1', $this->settings('pgsql', 'LATIN1')->charset);
    }

    private function settings(string $driver, string $charset = 'auto'): DatabaseSettings
    {
        return new DatabaseSettings(
            driver: $driver,
            host: $driver === 'sqlite' ? '' : 'localhost',
            database: $driver === 'sqlite' ? ':memory:' : 'test',
            port: $driver === 'sqlite' ? 0 : 5432,
            username: 'test',
            password: 'test',
            charset: $charset,
        );
    }
}
