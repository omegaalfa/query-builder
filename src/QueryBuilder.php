<?php

declare(strict_types=1);


namespace Omegaalfa\QueryBuilder;

use Omegaalfa\QueryBuilder\exceptions\DatabaseException;
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
     * Executa a query atual e retorna um PDOStatement.
     *
     * @param bool $bufferedQuery
     * @return PDOStatement
     * @throws DatabaseException
     */
    private function prepareAndExecute(bool $bufferedQuery = true): PDOStatement
    {
        $pdo = $this->connection->pdo($bufferedQuery);
        $stmt = $pdo->prepare($this->getQuerySql());

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

        $this->resetOperationsState();

        return $stmt;
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
        $threshold = 10000;
        $rowCount = $stmt->rowCount();
        if ($rowCount > 0 && $rowCount <= $threshold) {
            yield from $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // 🔹 Streaming real acima disso (memória constante)
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            yield $row;
        }
    }

    /**
     * @param array $items
     * @param int $size
     * @return iterable
     */
    private function chunk(array $items, int $size): iterable
    {
        for ($i = 0, $iMax = count($items); $i < $iMax; $i += $size) {
            yield array_slice($items, $i, $size);
        }
    }


}
