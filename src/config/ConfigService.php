<?php

declare(strict_types=1);


namespace Omegaalfa\QueryBuilder\config;


use InvalidArgumentException;
use Omegaalfa\QueryBuilder\utils\EnvLoader;

class ConfigService
{
    /**
     * Retorna a configuração de banco de dados validada.
     *
     * @return array{
     *     driver: string,
     *     host: string,
     *     database: string,
     *     port: int,
     *     username: string,
     *     password: string|null,
     *     charset: string,
     *     collation: string
     * }
     */
    public static function databaseConfig(): array
    {
        $config = [
            'driver' => EnvLoader::get('DB_DRIVER'),
            'host' => EnvLoader::get('DB_HOST'),
            'database' => EnvLoader::get('DB_DATABASE'),
            'port' => EnvLoader::get('DB_PORT'),
            'username' => EnvLoader::get('DB_USERNAME'),
            'password' => EnvLoader::get('DB_PASSWORD'),
            'charset' => EnvLoader::get('DB_CHARSET'),
            'collation' => EnvLoader::get('DB_COLLATION'),
        ];

        self::validate($config);

        return $config;
    }

    /**
     * Valida a configuração do banco de dados.
     *
     * @param array $config
     * @return void
     */
    private static function validate(array $config): void
    {
        $required = ['driver', 'host', 'database', 'username'];

        foreach ($required as $key) {
            if (empty($config[$key])) {
                throw new InvalidArgumentException(
                    sprintf('Configuração do banco de dados inválida: "%s" não pode estar vazio.', $key)
                );
            }
        }
    }
}
