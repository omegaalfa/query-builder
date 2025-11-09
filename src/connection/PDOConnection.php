<?php

declare(strict_types=1);


namespace Omegaalfa\QueryBuilder\connection;

use Omegaalfa\QueryBuilder\exceptions\DatabaseException;
use Omegaalfa\QueryBuilder\interfaces\ConnectionInterface;
use PDO;
use Throwable;


final class PDOConnection implements ConnectionInterface
{
    /**
     * @var PDO|null
     */
    private ?PDO $connection = null;

    /**
     * @var int
     */
    private int $transactionLevel = 0;
    /**
     * @var array
     */
    private array $savepoints = [];


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
     * @return int
     */
    public function getTransactionLevel(): int
    {
        return $this->transactionLevel;
    }

    /**
     * @return bool
     */
    public function inTransaction(): bool
    {
        return $this->transactionLevel > 0;
    }

    /**
     * Executa transações com rollback automático em caso de erro.
     * @param callable $callback
     * @return mixed
     * @throws DatabaseException
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this->connect());
            $this->commit();
            return $result;
        } catch (Throwable $e) {
            $this->rollBack();
            throw new DatabaseException(
                message: "Transaction failed: {$e->getMessage()}",
                code: (int)$e->getCode(),
                previousException: $e
            );
        }
    }

    /**
     * @return bool
     * @throws DatabaseException
     */
    public function beginTransaction(): bool
    {
        $pdo = $this->connect();

        if ($this->transactionLevel === 0) {
            $result = $pdo->beginTransaction();
            if ($result) {
                $this->transactionLevel++;
            }
            return $result;
        }

        // Cria savepoint para transação aninhada
        $savepoint = 'SAVEPOINT_' . $this->transactionLevel;
        $pdo->exec("SAVEPOINT {$savepoint}");
        $this->savepoints[] = $savepoint;
        $this->transactionLevel++;

        return true;
    }

    /**
     * Conecta apenas quando necessário.
     *
     * @param bool $bufferedQuery
     * @return PDO
     * @throws DatabaseException
     */
    public function connect(bool $bufferedQuery = true): PDO
    {
        if ($this->connection === null) {
            $dsn = $this->config->toDsn();

            try {
                if ($this->config->driver === 'mysql') {
                    $this->config->options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = $bufferedQuery;
                }

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
     * @return bool
     * @throws DatabaseException
     */
    public function commit(): bool
    {
        $pdo = $this->connect();

        if ($this->transactionLevel === 0) {
            throw new DatabaseException("No active transaction to commit");
        }

        $this->transactionLevel--;

        if ($this->transactionLevel === 0) {
            return $pdo->commit();
        }

        // Release savepoint
        $savepoint = array_pop($this->savepoints);
        $pdo->exec("RELEASE SAVEPOINT {$savepoint}");

        return true;
    }

    /**
     * @return bool
     * @throws DatabaseException
     */
    public function rollBack(): bool
    {
        $pdo = $this->connect();

        if ($this->transactionLevel === 0) {
            throw new DatabaseException("No active transaction to rollback");
        }

        $this->transactionLevel--;

        if ($this->transactionLevel === 0) {
            $this->savepoints = [];
            return $pdo->rollBack();
        }

        // Rollback to savepoint
        $savepoint = array_pop($this->savepoints);
        $pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}");

        return true;
    }

    /**
     * @return string
     */
    public function getDriver(): string
    {
        return $this->config->driver;
    }

    /**
     * @param bool $bufferedQuery
     * @return PDO
     * @throws DatabaseException
     */
    public function pdo(bool $bufferedQuery = true): PDO
    {
        try {
            return $this->connect($bufferedQuery);
        } catch (DatabaseException $e) {
            return $this->reconnect($bufferedQuery);
        }
    }

    /**
     * @param bool $bufferedQuery
     * @return PDO
     * @throws DatabaseException
     */
    public function reconnect(bool $bufferedQuery = true): PDO
    {
        $this->disconnect();
        return $this->connect($bufferedQuery);
    }

    /**
     * Fecha a conexão explicitamente.
     */
    public function disconnect(): void
    {
        $this->connection = null;
    }
}