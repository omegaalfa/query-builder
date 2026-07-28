<?php

declare(strict_types=1);


namespace Omegaalfa\QueryBuilder;

use InvalidArgumentException;
use JsonException;
use PDO;

final class DatabaseSettings
{
    /**
     * @param string $driver
     * @param string $host
     * @param string $database
     * @param int $port
     * @param string $username
     * @param string $password
     * @param array $options
     * @param string $charset
     * @param string $collation
     * @param string|null $prefix
     */
    public function __construct(
        public string          $driver,
        public readonly string $host,
        public readonly string $database,
        public readonly int    $port,
        public readonly string $username,
        public readonly string $password,
        public array           $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false
        ],
        public string          $charset = 'auto',
        public string          $collation = 'utf8mb4_unicode_ci',
        public ?string         $prefix = null,
    )
    {
        $this->driver = $this->normalizeDriver($driver);
        $this->charset = $this->charset !== 'auto'
            ? $this->charset
            : match ($this->driver) {
                'mysql' => 'utf8mb4',
                'pgsql' => 'UTF8',
                'sqlite' => '',
            };
        $this->validate();
    }

    /**
     * Normaliza o nome do driver e valida se é suportado.
     *
     * @param string $driver
     * @return string
     */
    private function normalizeDriver(string $driver): string
    {
        $driver = strtolower(trim($driver));

        if (!in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            throw new InvalidArgumentException("Unsupported database driver: {$driver}");
        }

        return $driver;
    }

    /**
     * Valida os dados básicos da configuração.
     *
     * @return void
     */
    private function validate(): void
    {
        if ($this->driver !== 'sqlite') {
            if (empty($this->host) || empty($this->database)) {
                throw new InvalidArgumentException('Database host and name must be provided.');
            }

            if ($this->port <= 0) {
                throw new InvalidArgumentException('Invalid database port number.');
            }
        }

        if ($this->charset !== '' && !preg_match('/^[a-zA-Z0-9_]+$/', $this->charset)) {
            throw new InvalidArgumentException('Invalid charset format.');
        }
    }

    /**
     * Gera a DSN (Data Source Name) conforme o driver.
     *
     * @return string
     */
    public function toDsn(): string
    {
        return match ($this->driver) {
            'mysql' => sprintf(
                'mysql:host=%s;dbname=%s;port=%d;charset=%s',
                $this->host,
                $this->database,
                $this->port,
                $this->charset
            ),

            'pgsql' => sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $this->host,
                $this->port,
                $this->database
            ),

            'sqlite' => sprintf('sqlite:%s', $this->database),

            default => throw new InvalidArgumentException("Unsupported database driver: {$this->driver}")
        };
    }

    /**
     * @return string
     */
    public function getDriver(): string
    {
        return $this->driver;
    }


    /**
     * @return string
     * @throws JsonException
     */
    public function getCacheKey(): string
    {
        $data = json_encode([
            'driver' => $this->driver,
            'host' => $this->host,
            'database' => $this->database,
            'port' => $this->port,
            'username' => $this->username,
            'charset' => $this->charset,
            'collation' => $this->collation,
            'prefix' => $this->prefix,
        ], JSON_THROW_ON_ERROR);

        return hash('sha256', $data);
    }
}
