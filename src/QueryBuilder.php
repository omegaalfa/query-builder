<?php

declare(strict_types=1);


namespace Omegaalfa\QueryBuilder;

use Omegaalfa\QueryBuilder\exceptions\DatabaseException;
use Omegaalfa\QueryBuilder\exceptions\QueryException;
use Omegaalfa\QueryBuilder\interfaces\CacheInterface;
use Omegaalfa\QueryBuilder\interfaces\ConnectionInterface;
use Omegaalfa\QueryBuilder\interfaces\PaginatorInterface;
use Omegaalfa\QueryBuilder\interfaces\QueryLoggerInterface;
use Omegaalfa\QueryBuilder\traits\QueryBuilderCacheTrait;
use PDO;
use PDOException;
use PDOStatement;

final class QueryBuilder extends QueryBuilderOperations
{
    use QueryBuilderCacheTrait;

    /**
     * @var string|false
     */
    private string|false $insertId = false;

    /**
     * @param ConnectionInterface $connection
     * @param PaginatorInterface $paginator
     * @param CacheInterface|null $cache
     * @param QueryLoggerInterface|null $logger
     */
    public function __construct(
        private readonly ConnectionInterface   $connection,
        private readonly PaginatorInterface    $paginator,
        private readonly ?CacheInterface       $cache = null,
        private readonly ?QueryLoggerInterface $logger = null
    )
    {
        $this->setDriver($this->connection->getDriver());
    }

    /**
     * Obtém o último ID inserido.
     *
     * @return false|string
     */
    public function getInsertId(): false|string
    {
        return $this->insertId;
    }

    /**
     * Executa várias queries dentro de uma transação.
     *
     * @param callable $callback
     * @return mixed
     * @throws exceptions\DatabaseException
     */
    public function transactional(callable $callback): mixed
    {
        return $this->connection->transaction(function (PDO $pdo) use ($callback) {
            return $callback($this, $pdo);
        });
    }

    /**
     * Retorna SQL formatado para debug
     */
    public function toSql(bool $withParams = false): string
    {
        $sql = $this->getQuerySql();

        if (!$withParams || empty($this->params)) {
            return $sql;
        }

        // Substitui placeholders por valores (APENAS PARA DEBUG!)
        $debugSql = $sql;
        foreach ($this->params as $param => $value) {
            $replace = match (true) {
                is_null($value) => 'NULL',
                is_bool($value) => $value ? '1' : '0',
                is_int($value) || is_float($value) => (string)$value,
                $value instanceof \DateTimeInterface => "'" . $value->format('Y-m-d H:i:s') . "'",
                default => "'" . addslashes((string)$value) . "'"
            };

            $debugSql = str_replace($param, $replace, $debugSql);
        }

        return $debugSql;
    }

    /**
     * Executa EXPLAIN na query atual para análise de performance
     *
     * @return array
     * @throws DatabaseException
     */
    public function explain(): array
    {
        $sql = $this->getQuerySql();

        // Adiciona EXPLAIN conforme o driver
        $explainSql = match ($this->driver) {
            'pgsql' => "EXPLAIN (FORMAT JSON, ANALYZE) $sql",
            'sqlite' => "EXPLAIN QUERY PLAN $sql",
            default => "EXPLAIN $sql"
        };

        try {
            $pdo = $this->connection->pdo(true);
            $stmt = $pdo->prepare($explainSql);

            foreach ($this->params as $param => $value) {
                if ($value instanceof \DateTimeInterface) {
                    $stmt->bindValue($param, $value->format('Y-m-d H:i:s'));
                } elseif (is_int($value)) {
                    $stmt->bindValue($param, $value, PDO::PARAM_INT);
                } elseif (is_bool($value)) {
                    $stmt->bindValue($param, (int)$value, PDO::PARAM_INT);
                } else {
                    $stmt->bindValue($param, (string)$value);
                }
            }

            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (\PDOException $e) {
            throw new DatabaseException(
                "EXPLAIN failed: {$e->getMessage()}",
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * Executa e retorna o resultado formatado (com cache e paginação).
     *
     * @param bool $bufferedQuery
     * @return QueryResultDTO
     * @throws DatabaseException
     * @throws QueryException
     */
    public function execute(bool $bufferedQuery = true): QueryResultDTO
    {
        try {
            if ($cached = $this->getFromCache()) {
                $this->resetOperationsState();
                return $cached;
            }

            $stmt = $this->prepareAndExecute($bufferedQuery);
            $count = $stmt->rowCount();

            $pagination = null;
            if ($this->limit) {
                $total = $this->getTotalCount();
                $pagination = $this->paginator->paginate(
                    total: $total,
                    perPage: $this->limit[0],
                    currentPage: (int)($this->limit[1] / $this->limit[0]) + 1
                );
            }

            $isCacheEnabled = isset($this->cacheTtl) && $this->cache !== null;
            $data = $this->streamData($stmt);
            if ($isCacheEnabled) {
                $result = new QueryResultDTO(iterator_to_array($data, false), $count, $pagination);
                $this->saveToCache($result);
                return $result;
            }

            return new QueryResultDTO($data, $count, $pagination);
        } catch (PDOException $e) {
            throw new QueryException(
                message: "Query execution failed: {$e->getMessage()}",
                code: (int)$e->getCode(),
                previousException: $e
            );
        }
    }

    /**
     * Executa a query atual e retorna um PDOStatement.
     *
     * @param bool $bufferedQuery
     * @return PDOStatement
     * @throws DatabaseException
     */
    private function prepareAndExecute(bool $bufferedQuery = true): PDOStatement
    {
        $startTime = microtime(true);
        $sql = $this->getQuerySql();
        try {
            $pdo = $this->connection->pdo($bufferedQuery);
            $stmt = $pdo->prepare($sql);
            foreach ($this->params as $param => $value) {
                // 🔸 Nulos
                if ($value === null) {
                    $stmt->bindValue($param, null, PDO::PARAM_NULL);
                    continue;
                }

                // 🔸 Arrays (não são suportados diretamente)
                if (is_array($value)) {
                    throw new DatabaseException("Invalid binding for {$param}: arrays are not supported here.");
                }

                // 🔸 DateTime → converte para string compatível com SQL
                if ($value instanceof \DateTimeInterface) {
                    $stmt->bindValue($param, $value->format('Y-m-d H:i:s'));
                    continue;
                }

                // 🔸 Inteiros
                if (is_int($value)) {
                    $stmt->bindValue($param, $value, PDO::PARAM_INT);
                    continue;
                }

                // 🔸 Booleanos
                if (is_bool($value)) {
                    $stmt->bindValue($param, (int)$value, PDO::PARAM_INT);
                    continue;
                }

                // 🔸 Recursos (arquivos, streams, blobs)
                if (is_resource($value)) {
                    $stmt->bindParam($param, $value, PDO::PARAM_LOB);
                    continue;
                }

                // 🔸 Qualquer outro tipo (string, float, double, etc)
                $stmt->bindValue($param, (string)$value);
            }

            $stmt->execute();
            $this->insertId = $pdo->lastInsertId();

            if ($this->logger) {
                $duration = microtime(true) - $startTime;
                $this->logger->logQuery($sql, $this->params, $duration, $stmt->rowCount());
            }

            $this->resetOperationsState();

            return $stmt;
        } catch (\PDOException $e) {
            $this->logger?->logError($sql, $this->params, $e);
            throw $e;
        }

    }

    /**
     * Obtém total para paginação.
     *
     * @return int
     * @throws DatabaseException
     */
    private function getTotalCount(): int
    {
        $countQuery = clone $this;
        $countQuery->sql = ['SELECT', 'COUNT(*) as total', "FROM {$this->table}"];
        $countQuery->orderBy = [];
        $countQuery->limit = null;

        $stmt = $countQuery->prepareAndExecute(true);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int)($result['total'] ?? 0);
    }

    /**
     * @param PDOStatement $stmt
     * @return iterable
     */
    private function streamData(PDOStatement $stmt): iterable
    {
        try {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                yield $row;
            }
        } finally {
            $stmt->closeCursor();
        }
    }

    /**
     * Retorna soma de uma coluna
     *
     * @param string $column
     * @return float
     * @throws DatabaseException
     * @throws QueryException
     */
    public function sum(string $column): float
    {
        $originalSql = $this->sql;
        $this->sql[1] = "SUM({$this->quoteIdentifier($column)}) as total";

        try {
            $result = $this->execute();
            $data = is_array($result->data) ? $result->data : iterator_to_array($result->data);
            return (float)($data[0]['total'] ?? 0);
        } finally {
            $this->sql = $originalSql;
        }
    }

    /**
     * Retorna média de uma coluna
     *
     * @param string $column
     * @return float
     * @throws DatabaseException
     * @throws QueryException
     */
    public function avg(string $column): float
    {
        $originalSql = $this->sql;
        $this->sql[1] = "AVG({$this->quoteIdentifier($column)}) as average";

        try {
            $result = $this->execute();
            $data = is_array($result->data) ? $result->data : iterator_to_array($result->data);
            return (float)($data[0]['average'] ?? 0);
        } finally {
            $this->sql = $originalSql;
        }
    }

    /**
     * Retorna valor máximo
     *
     * @param string $column
     * @return mixed
     * @throws DatabaseException
     * @throws QueryException
     */
    public function max(string $column): mixed
    {
        $originalSql = $this->sql;
        $this->sql[1] = "MAX({$this->quoteIdentifier($column)}) as maximum";

        try {
            $result = $this->execute();
            $data = is_array($result->data) ? $result->data : iterator_to_array($result->data);
            return $data[0]['maximum'] ?? null;
        } finally {
            $this->sql = $originalSql;
        }
    }

    /**
     * Retorna valor mínimo
     *
     * @param string $column
     * @return mixed
     * @throws DatabaseException
     * @throws QueryException
     */
    public function min(string $column): mixed
    {
        $originalSql = $this->sql;
        $this->sql[1] = "MIN({$this->quoteIdentifier($column)}) as minimum";

        try {
            $result = $this->execute();
            $data = is_array($result->data) ? $result->data : iterator_to_array($result->data);
            return $data[0]['minimum'] ?? null;
        } finally {
            $this->sql = $originalSql;
        }
    }

    /**
     * Verifica se existe algum registro
     *
     * @return bool
     * @throws DatabaseException
     * @throws QueryException
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Retorna contagem de registros
     *
     * @param string $column
     * @return int
     * @throws DatabaseException
     * @throws QueryException
     */
    public function count(string $column = '*'): int
    {
        $originalSql = $this->sql;
        $this->sql[1] = "COUNT({$this->quoteIdentifier($column)}) as total";

        try {
            $result = $this->execute();
            $data = is_array($result->data) ? $result->data : iterator_to_array($result->data);
            return (int)($data[0]['total'] ?? 0);
        } finally {
            $this->sql = $originalSql;
        }
    }
}
