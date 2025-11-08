<?php

declare(strict_types=1);


namespace Omegaalfa\QueryBuilder;

use Omegaalfa\QueryBuilder\exceptions\QueryException;
use Omegaalfa\QueryBuilder\interfaces\CacheInterface;
use Omegaalfa\QueryBuilder\interfaces\ConnectionInterface;
use Omegaalfa\QueryBuilder\interfaces\PaginatorInterface;
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
     */
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly PaginatorInterface  $paginator,
        private readonly ?CacheInterface     $cache = null
    )
    {
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
     * Executa a query atual e retorna um PDOStatement.
     *
     * @return PDOStatement
     * @throws QueryException
     * @throws exceptions\DatabaseException
     */
    private function prepareAndExecute(): PDOStatement
    {
        $pdo = $this->connection->pdo();
        $stmt = $pdo->prepare($this->getQuerySql());

        foreach ($this->params as $param => $value) {
            if ($value === '' || $value === [] || $value === null) {
                throw new QueryException("Field {$param} is empty or invalid.");
            }
            if (is_array($value)) {
                $stmt->bindValue($param, $value);
                continue;
            }
            $stmt->bindValue($param, $value);
        }

        $stmt->execute();
        $this->insertId = $pdo->lastInsertId();

        $this->resetOperationsState();

        return $stmt;
    }

    /**
     * Executa e retorna o resultado formatado (com cache e paginação).
     *
     * @return QueryResultDTO
     * @throws QueryException
     * @throws exceptions\DatabaseException
     */
    public function execute(): QueryResultDTO
    {
        try {
            if ($cached = $this->getFromCache()) {
                $this->resetOperationsState();
                return $cached;
            }

            $stmt = $this->prepareAndExecute();
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

            $result = new QueryResultDTO($this->streamData($stmt), $count, $pagination);
            $this->saveToCache($result);

            return $result;
        } catch (PDOException $e) {
            throw new QueryException(
                message: "Query execution failed: {$e->getMessage()}",
                code: (int)$e->getCode(),
                previousException: $e
            );
        }
    }

    /**
     * Obtém total para paginação.
     *
     * @return int
     * @throws QueryException
     * @throws exceptions\DatabaseException
     */
    private function getTotalCount(): int
    {
        $countQuery = clone $this;
        $countQuery->sql = ['SELECT', 'COUNT(*) as total', "FROM {$this->table}"];
        $countQuery->orderBy = [];
        $countQuery->limit = null;

        $stmt = $countQuery->prepareAndExecute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int)($result['total'] ?? 0);
    }

    /**
     * @param PDOStatement $stmt
     * @return iterable
     */
    private function streamData(PDOStatement $stmt): iterable
    {
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            yield $row;
        }
    }
}
