<?php

declare(strict_types=1);


namespace Omegaalfa\QueryBuilder;

use Omegaalfa\QueryBuilder\config\ConfigService;
use Omegaalfa\QueryBuilder\connection\DatabaseSettings;
use Omegaalfa\QueryBuilder\connection\PDOConnection;
use Omegaalfa\QueryBuilder\interfaces\CacheInterface;


class DatabaseFactory
{

    /**
     * @param CacheInterface|null $cache
     *
     * @return QueryBuilder
     */
    public static function create(?CacheInterface $cache = null): QueryBuilder
    {
        $config = ConfigService::databaseConfig();
        return new QueryBuilder(
            new PDOConnection(
                new DatabaseSettings(
                    driver: $config['driver'],
                    host: $config['host'],
                    database: $config['database'],
                    port: $config['port'],
                    username: $config['username'],
                    password: $config['password'],
                    charset: $config['charset'],
                    collation: $config['collation'],
                )
            ),
            new Paginator(),
            $cache
        );
    }
}
