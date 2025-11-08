<?php

declare(strict_types=1);


namespace Omegaalfa\QueryBuilder\connection;

use InvalidArgumentException;

readonly class DatabaseSettings
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
        public string  $driver,
        public string  $host,
        public string  $database,
        public int     $port,
        public string  $username,
        public string  $password,
        public array   $options = [],
        public string  $charset = 'utf8mb4',
        public string  $collation = 'utf8mb4_unicode_ci',
        public ?string $prefix = null,
    )
    {
        $this->validate();
    }

    /**
     * Valida os dados básicos da configuração.
     *
     * @return void
     */
    private function validate(): void
    {
        if (empty($this->driver)) {
            throw new InvalidArgumentException('Database driver must be defined.');
        }

        if ($this->driver !== 'sqlite') {
            if (empty($this->host) || empty($this->database)) {
                throw new InvalidArgumentException('Database host and name must be provided.');
            }

            if ($this->port <= 0) {
                throw new InvalidArgumentException('Invalid database port number.');
            }
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
}
