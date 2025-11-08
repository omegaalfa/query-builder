<?php

declare(strict_types=1);


namespace Omegaalfa\QueryBuilder\connection;

use Omegaalfa\QueryBuilder\exceptions\DatabaseException;
use Omegaalfa\QueryBuilder\interfaces\ConnectionInterface;
use PDO;
use Throwable;


final class PDOConnection implements ConnectionInterface
{
    private ?PDO $connection = null;

    /**
     * @param DatabaseSettings $config
     */
    public function __construct(protected DatabaseSettings $config)
    {
    }

    /**
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->connection !== null;
    }

    /**
     * Executa transações com rollback automático em caso de erro.
     * @param callable $callback
     * @return mixed
     * @throws DatabaseException
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->connect();

        try {
            $pdo->beginTransaction();

            $result = $callback($pdo);

            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw new DatabaseException(
                message: "Transaction failed: {$e->getMessage()}",
                code: (int)$e->getCode(),
                previousException: $e
            );
        }
    }

    /**
     * Conecta apenas quando necessário.
     *
     * @return PDO
     * @throws DatabaseException
     */
    public function connect(): PDO
    {
        if ($this->connection === null) {
            $dsn = $this->config->toDsn();

            try {
                $this->connection = new PDO(
                    $dsn,
                    $this->config->username,
                    $this->config->password,
                    $this->config->options
                );
                $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                if (!empty($this->config->collation)) {
                    $this->connection->exec(
                        "SET NAMES '{$this->config->charset}' COLLATE '{$this->config->collation}'"
                    );
                }
            } catch (Throwable $e) {
                throw new DatabaseException(
                    message: "Database connection failed: {$e->getMessage()}",
                    code: (int)$e->getCode(),
                    previousException: $e
                );
            }
        }

        return $this->connection;
    }


    /**
     * @return PDO
     * @throws DatabaseException
     */
    public function reconnect(): PDO
    {
        $this->disconnect();
        return $this->connect();
    }

    /**
     * Fecha a conexão explicitamente.
     */
    public function disconnect(): void
    {
        $this->connection = null;
    }

    /**
     * @return string
     */
    public function getDriver(): string
    {
        return $this->config->driver;
    }

    /**
     * Exposição direta do PDO, para casos específicos.
     *
     * @return PDO
     * @throws DatabaseException
     */
    public function pdo(): PDO
    {
        try {
            return $this->connect();
        } catch (DatabaseException $e) {
            return $this->reconnect();
        }
    }
}