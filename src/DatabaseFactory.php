<?php

declare(strict_types=1);


namespace Omegaalfa\QueryBuilder;

use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\Interfaces\CacheInterface;


class DatabaseFactory
{

    /**
     * @param DatabaseSettings $settings
     * @param CacheInterface|null $cache
     *
     * @return QueryBuilder
     */
    public static function create(DatabaseSettings $settings, ?CacheInterface $cache = null): QueryBuilder
    {
        return new QueryBuilder(
            new PDOConnection($settings),
            cache: $cache
        );
    }
}
