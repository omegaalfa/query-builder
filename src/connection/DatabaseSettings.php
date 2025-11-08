<?php

declare(strict_types=1);


namespace Omegaalfa\QueryBuilder\connection;

use InvalidArgumentException;
use PDO;

final readonly class DatabaseSettings
{
    /**
     * @var string
     */
    public string $driver;

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
        string         $driver = 'mysql',
        public string  $host,
        public string  $database,
        public int     $port,
        public string  $username,
        public string  $password,
        public array   $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false
        ],
        public string  $charset = 'utf8mb4',
        public string  $collation = 'utf8mb4_unicode_ci',
        public ?string $prefix = null,
    )
    {
        $this->driver = $this->normalizeDriver($driver);
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

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $this->charset)) {
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


}
