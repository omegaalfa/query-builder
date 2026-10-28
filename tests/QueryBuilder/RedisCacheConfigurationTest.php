<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use Omegaalfa\QueryBuilder\Cache\RedisCache;
use Omegaalfa\QueryBuilder\Exceptions\CacheException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RedisCacheConfigurationTest extends TestCase
{
    /** @param array<string, mixed> $arguments */
    #[DataProvider('invalidConfiguration')]
    public function testRejectsInvalidConnectionConfiguration(array $arguments): void
    {
        $this->expectException(CacheException::class);
        new RedisCache('127.0.0.1', ...$arguments);
    }

    public static function invalidConfiguration(): iterable
    {
        yield 'port below range' => [['port' => 0]];
        yield 'port above range' => [['port' => 65_536]];
        yield 'non-positive timeout' => [['timeout' => 0.0]];
        yield 'negative database' => [['database' => -1]];
    }
}
