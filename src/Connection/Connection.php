<?php

declare(strict_types=1);

namespace Omegaalfa\QueryBuilder\Connection;

use JsonException;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\QueryBuilder;
use PDOException;
use RuntimeException;

/**
 * Connection Singleton
 * Gerencia conexão PDO única para uso com Query Builder
 */
class Connection
{
    /**
     * @var array
     */
    private static array $instances = [];

    /**
     * @param DatabaseSettings $dbSettings
     * @return QueryBuilder
     * @throws JsonException
     */
    public static function create(DatabaseSettings $dbSettings): QueryBuilder
    {
        $key = $dbSettings->getCacheKey();
        if (!isset(self::$instances[$key])) {
            try {
                $connection = new PDOConnection($dbSettings);
                self::$instances[$key] = new QueryBuilder($connection);

            } catch (PDOException $e) {
                throw new RuntimeException(
                    sprintf(
                        "Erro ao conectar ao banco [%s]: %s",
                        $dbSettings->getDriver(),
                        $e->getMessage()
                    ),
                    $e->getCode(),
                    $e
                );
            }
        }

        return self::$instances[$key];
    }

    /**
     * @param DatabaseSettings  $dbSettings
     * @return void
     * @throws JsonException
     */
    public static function remove(DatabaseSettings $dbSettings): void
    {
        $key = $dbSettings->getCacheKey();
        unset(self::$instances[$key]);
    }

    /**
     * Limpa todas as conexões em cache
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$instances = [];
    }

    /**
     * @return int
     */
    public static function count(): int
    {
        return count(self::$instances);
    }

}
