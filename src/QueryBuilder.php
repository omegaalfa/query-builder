<?php

declare(strict_types=1);


namespace Omegaalfa\QueryBuilder;

use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\Exceptions\DatabaseException;
use Omegaalfa\QueryBuilder\Exceptions\QueryException;
use Omegaalfa\QueryBuilder\Exceptions\UnsupportedDatabaseFeatureException;
use Omegaalfa\QueryBuilder\Interfaces\CacheInterface;
use Omegaalfa\QueryBuilder\Interfaces\ConnectionInterface;
use Omegaalfa\QueryBuilder\Interfaces\DatabaseValueInterface;
use Omegaalfa\QueryBuilder\Interfaces\PaginatorInterface;
use Omegaalfa\QueryBuilder\Interfaces\QueryLoggerInterface;
use Omegaalfa\QueryBuilder\Internal\RollbackFailureContext;
use Omegaalfa\QueryBuilder\Traits\QueryBuilderCacheTrait;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

final class QueryBuilder extends QueryBuilderOperations
{
    use QueryBuilderCacheTrait;

    /**
     * @var string|false
     */
    private string|false $insertId = false;

    /**
     * @param ConnectionInterface $connection
     * @param ?PaginatorInterface $paginator
     * @param CacheInterface|null $cache
     * @param QueryLoggerInterface|null $logger
     */
    public function __construct(
        private readonly ConnectionInterface   $connection,
        private ?PaginatorInterface            $paginator = null,
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

        if (!$withParams) {
            return $sql;
        }

        $params = $this->currentQueryParams();
        if ($params === []) {
            return $sql;
        }
        $formatValue = static function (mixed $value): string {
            if ($value instanceof DatabaseValueInterface) {
                $value = $value->value();
            }
            return match (true) {
                is_null($value) => 'NULL',
                is_bool($value) => $value ? '1' : '0',
                is_int($value) || is_float($value) => (string) $value,
                $value instanceof \DateTimeInterface => "'" . $value->format('Y-m-d H:i:s') . "'",
                default => "'" . addslashes((string) $value) . "'",
            };
        };

        if ($this->ctes !== []) {
            return $this->replaceSqlPlaceholders(
                $sql,
                static fn (string $placeholder): ?string => array_key_exists($placeholder, $params)
                    ? $formatValue($params[$placeholder])
                    : null,
            );
        }

        // Substitui placeholders por valores (APENAS PARA DEBUG!)
        $debugSql = $sql;
        uksort($params, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));
        foreach ($params as $param => $value) {
            $replace = $formatValue($value);

            $debugSql = preg_replace_callback(
                '/' . preg_quote($param, '/') . '(?![A-Za-z0-9_])/',
                static fn (): string => $replace,
                $debugSql,
            ) ?? $debugSql;
        }

        return $debugSql;
    }

    /**
     * Executa EXPLAIN na query atual para análise de performance
     *
     * @return array<string, mixed>
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
            $params = $this->currentQueryParams();

            foreach ($params as $param => $value) {
                if ($value instanceof DatabaseValueInterface) {
                    $stmt->bindValue($param, $value->value(), $value->pdoType());
                    continue;
                }
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
        $enableMysqlBatchPositionals = $this->driver === 'mysql'
            && $this->batchInsert
            && $this->insertColumns !== []
            && count($this->params) > count($this->insertColumns);
        if ($enableMysqlBatchPositionals) {
            $this->batchPositionalExecution = true;
        }

        try {
            if (
                $this->driver === 'sqlite'
                && $this->batchInsert
                && $this->sqliteBatchRows !== null
                && $this->returningColumns === []
            ) {
                return $this->executeSQLiteBatchIncrementally($bufferedQuery);
            }

            $cacheEligible = $bufferedQuery
                && $this->isCacheEligibleForCurrentQuery()
                && $this->supportsReliableCacheInvalidation()
                && !$this->hasActiveOrUnknownTransaction($bufferedQuery);
            $cacheTags = $cacheEligible ? $this->cacheTagsForCurrentTable() : [];
            if ($cacheEligible && ($cached = $this->getFromCache())) {
                $this->consumeCacheTtl();
                $this->clearCteBindings();
                $this->resetOperationsState(preserveCacheKey: true);
                return $cached;
            }

            $pagination = null;
            if ($this->paginationRequested && $this->limit) {
                $total = $this->getTotalCount();
                $this->paginator ??= new Paginator();
                $pagination = $this->paginator->paginate(
                    total: $total,
                    perPage: $this->limit[0],
                    currentPage: (int)($this->limit[1] / $this->limit[0]) + 1
                );
            }

            $stmt = $this->prepareAndExecute($bufferedQuery);
            $count = $stmt->rowCount();

            $isCacheEnabled = $cacheEligible
                && $this->cacheTtl > 0
                && $this->cache !== null
                && !$this->hasActiveOrUnknownTransaction($bufferedQuery);
            $data = $this->streamData($stmt);
            if ($this->cursorPagination !== null) {
                $rows = iterator_to_array($data, false);
                $hasMore = count($rows) > $this->cursorPagination['perPage'];
                if ($hasMore) {
                    array_pop($rows);
                }
                $nextCursor = null;
                if ($hasMore && $rows !== []) {
                    $column = $this->cursorPagination['column'];
                    $column = str_contains($column, '.') ? substr($column, strrpos($column, '.') + 1) : $column;
                    $value = $rows[array_key_last($rows)][$column] ?? null;
                    if ($this->cursorPagination['tieBreaker'] !== null) {
                        $tieBreaker = $this->cursorPagination['tieBreaker'];
                        $tieBreaker = str_contains($tieBreaker, '.') ? substr($tieBreaker, strrpos($tieBreaker, '.') + 1) : $tieBreaker;
                        $tieBreakerValue = $rows[array_key_last($rows)][$tieBreaker] ?? null;
                        $value = $tieBreakerValue === null ? null : [$value, $tieBreakerValue];
                    }
                    if ($value !== null) {
                        try {
                            $nextCursor = base64_encode(json_encode($value, JSON_THROW_ON_ERROR));
                        } catch (\JsonException $e) {
                            throw new QueryException(
                                message: 'Unable to encode cursor.',
                                previousException: $e
                            );
                        }
                    }
                }
                $pagination = new CursorPaginationDTO(
                    $this->cursorPagination['perPage'],
                    $nextCursor,
                    $hasMore,
                );
                $count = count($rows);
                $data = $rows;
            }
            if ($isCacheEnabled) {
                $result = new QueryResultDTO(iterator_to_array($data, false), $count, $pagination, $this->insertId);
                $this->saveToCache($result, $cacheTags);
                $this->consumeCacheTtl();
                $this->clearCteBindings();
                return $result;
            }

            $result = new QueryResultDTO($data, $count, $pagination, $this->insertId);
            $this->consumeCacheTtl();
            $this->clearCteBindings();
            return $result;
        } catch (PDOException $e) {
            throw new QueryException(
                message: "Query execution failed: {$e->getMessage()}",
                code: (int)$e->getCode(),
                previousException: $e
            );
        } finally {
            if ($enableMysqlBatchPositionals) {
                $this->batchPositionalExecution = false;
            }
        }
    }

    private function executeSQLiteBatchIncrementally(bool $bufferedQuery): QueryResultDTO
    {
        $rows = $this->sqliteBatchRows ?? [];
        $pdo = $this->connection->pdo($bufferedQuery);
        $startedTransaction = false;
        $count = 0;
        $lastInsertId = false;
        $offset = 0;
        $rawSavepoint = null;

        try {
            if ($this->connection instanceof PDOConnection) {
                if ($this->connection->getTransactionLevel() > 0) {
                    $this->connection->beginTransaction();
                    $startedTransaction = true;
                } elseif (!$pdo->inTransaction()) {
                    $this->connection->beginTransaction();
                    $startedTransaction = true;
                } else {
                    $rawSavepoint = 'QB_BATCH_' . bin2hex(random_bytes(8));
                    $pdo->exec('SAVEPOINT ' . $rawSavepoint);
                }
            }
            while ($offset < count($rows)) {
                $length = min(450, count($rows) - $offset);
                $failure = null;
                while (true) {
                    $chunk = clone $this;
                    $chunk->sqliteBatchRows = array_slice($rows, $offset, $length);
                    // Keep the single-row fallback for drivers/adapters that report
                    // SQLite variable-limit failures through named placeholders.
                    $chunk->batchPositionalExecution = $length > 1;
                    try {
                        $statement = $chunk->prepareAndExecute($bufferedQuery);
                        $count += $statement->rowCount();
                        $lastInsertId = $chunk->insertId;
                        break;
                    } catch (PDOException $exception) {
                        $tooManyVariables = str_contains(strtolower($exception->getMessage()), 'too many sql variables');
                        if (!$tooManyVariables || $length <= 1) {
                            if ($tooManyVariables && $length === 1) {
                                $failure = new QueryException(
                                    message: 'cannot prepare one insertBatch row: ' . $exception->getMessage(),
                                    sql: $chunk->getQuerySql(),
                                    bindings: $chunk->currentQueryParams(),
                                    previousException: $exception,
                                );
                            } else {
                                $failure = new QueryException(
                                    message: 'Query execution failed: ' . $exception->getMessage(),
                                    code: (int) $exception->getCode(),
                                    previousException: $exception,
                                );
                            }
                            break;
                        }
                        $length = max(1, intdiv($length, 2));
                    }
                }
                if ($failure !== null) {
                    if ($startedTransaction) {
                        $rollbackException = $this->rollbackSQLiteBatchTransaction($pdo);
                        if ($rollbackException !== null) {
                            $context = new RollbackFailureContext($failure, $rollbackException);
                            $startedTransaction = false;
                            throw $this->sqliteBatchRollbackFailureException($context);
                        }
                        $startedTransaction = false;
                    } elseif ($rawSavepoint !== null) {
                        $pdo->exec('ROLLBACK TO SAVEPOINT ' . $rawSavepoint);
                        $pdo->exec('RELEASE SAVEPOINT ' . $rawSavepoint);
                    }
                    throw $failure;
                }
                $offset += $length;
            }

            if ($startedTransaction) {
                $commitException = $this->commitSQLiteBatchTransaction($pdo);
                if ($commitException !== null) {
                    throw new DatabaseException(
                        'Transaction failed: ' . $commitException->getMessage(),
                        (int) $commitException->getCode(),
                        $commitException,
                    );
                }
            } elseif ($rawSavepoint !== null) {
                $pdo->exec('RELEASE SAVEPOINT ' . $rawSavepoint);
            }

            $this->insertId = $lastInsertId;
            $result = new QueryResultDTO([], $count, null, $lastInsertId);
            $this->consumeCacheTtl();
            $this->clearCteBindings();
            $this->resetOperationsState(preserveCacheKey: true);
            return $result;
        } catch (\Throwable $exception) {
            if ($startedTransaction) {
                $rollbackException = $this->rollbackSQLiteBatchTransaction($pdo);
                if ($rollbackException !== null) {
                    $context = new RollbackFailureContext($exception, $rollbackException);
                    throw $this->sqliteBatchRollbackFailureException($context);
                }
            }
            throw $exception;
        }
    }

    private function rollbackSQLiteBatchTransaction(PDO $pdo): ?Throwable
    {
        try {
            if ($this->connection instanceof PDOConnection) {
                $this->connection->rollBack();
            } else {
                $pdo->rollBack();
            }
        } catch (\Throwable $exception) {
            return $exception;
        }

        return null;
    }

    private function commitSQLiteBatchTransaction(PDO $pdo): ?Throwable
    {
        try {
            if ($this->connection instanceof PDOConnection) {
                $this->connection->commit();
            } else {
                $pdo->commit();
            }
        } catch (Throwable $exception) {
            return $exception;
        }

        return null;
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
        $captureInsertId = $this->returnLastInsertIdRequested;
        $this->insertId = false;
        $params = [];
        $logParams = [];
        try {
            $pdo = $this->connection->pdo($bufferedQuery);
            $minimumSqliteVersion = null;
            if ($this->driver === 'sqlite') {
                $minimumSqliteVersion = match (true) {
                    $this->returningColumns !== [] => '3.35.0',
                    $this->conflictActionDefined => '3.24.0',
                    default => null,
                };
            }
            if ($minimumSqliteVersion !== null) {
                $sqliteVersion = $pdo->query('SELECT sqlite_version()')->fetchColumn();
                if (!is_string($sqliteVersion) || version_compare($sqliteVersion, $minimumSqliteVersion, '<')) {
                    $feature = $this->returningColumns !== [] ? 'RETURNING' : 'UPSERT';
                    throw new UnsupportedDatabaseFeatureException(
                        sprintf('SQLite %s requires SQLite %s or newer.', $feature, $minimumSqliteVersion)
                    );
                }
            }
            $params = $this->currentQueryParams();
            $logParams = $this->batchPositionalExecution
                ? $this->currentNamedQueryParams()
                : $params;
            $stmt = $pdo->prepare($sql);
            foreach ($params as $param => $value) {
                if ($value instanceof DatabaseValueInterface) {
                    $stmt->bindValue($param, $value->value(), $value->pdoType());
                    continue;
                }
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

            if ($captureInsertId) {
                if ($this->driver === 'pgsql') {
                    $returnedId = $stmt->fetchColumn();
                    $this->insertId = $returnedId === false ? false : (string) $returnedId;
                } else {
                    $this->insertId = $pdo->lastInsertId();
                }
            }

            if ($this->logger) {
                $duration = microtime(true) - $startTime;
                $this->logger->logQuery($sql, $logParams, $duration, $stmt->rowCount());
            }

            if ($this->operationType !== null
                || str_starts_with(strtoupper(ltrim($sql)), 'INSERT')
                || str_starts_with(strtoupper(ltrim($sql)), 'UPDATE')
                || str_starts_with(strtoupper(ltrim($sql)), 'DELETE')) {
                $this->invalidateCacheTags($bufferedQuery);
            }

            $this->resetOperationsState(preserveCacheKey: true);

            return $stmt;
        } catch (\PDOException $e) {
            $this->logger?->logError($sql, $logParams, $e);
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

        $countSql = $countQuery->getQuerySql();
        $countQuery->params = array_filter(
            $countQuery->params,
            static fn (mixed $value, string $placeholder): bool => preg_match(
                '/(^|[^a-zA-Z0-9_])' . preg_quote($placeholder, '/') . '(?=$|[^a-zA-Z0-9_])/',
                $countSql,
            ) === 1,
            ARRAY_FILTER_USE_BOTH,
        );

        $stmt = $countQuery->prepareAndExecute(true);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int)($result['total'] ?? 0);
    }

    /**
     * @param PDOStatement $stmt
     * @return iterable<int, array<string, mixed>>
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
        return (float) $this->executeAggregate('SUM', $column, 'total');
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
        return (float) $this->executeAggregate('AVG', $column, 'average');
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
        return $this->executeAggregate('MAX', $column, 'maximum');
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
        return $this->executeAggregate('MIN', $column, 'minimum');
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
        return (int) $this->executeAggregate('COUNT', $column, 'total');
    }

    private function executeAggregate(string $function, string $column, string $alias): mixed
    {
        $this->assertAggregateQuery();
        $aggregate = clone $this;
        $aggregate->sql[1] = sprintf('%s(%s) AS %s', $function, $aggregate->quoteIdentifier($column), $alias);
        $result = $aggregate->execute();
        $this->clearCteBindings();
        $data = is_array($result->data) ? $result->data : iterator_to_array($result->data);

        return $data[0][$alias] ?? null;
    }

    private function assertAggregateQuery(): void
    {
        if (($this->sql[0] ?? null) !== 'SELECT' || !array_key_exists(1, $this->sql)) {
            throw new QueryException('Aggregate methods require a query created with select(); raw() queries are not supported.');
        }
    }

    private function sqliteBatchRollbackFailureException(
        \Omegaalfa\QueryBuilder\Internal\RollbackFailureContext $context,
    ): \Throwable {
        $originalFailure = $context->getOriginalFailure();
        $rollbackFailure = $context->getRollbackFailure();
        $message = sprintf(
            'SQLite batch failed and rollback was not confirmed; the enclosing transaction may contain partial changes. Original failure: %s. Rollback failure: %s',
            $originalFailure->getMessage(),
            $rollbackFailure->getMessage(),
        );

        return new DatabaseException(
            message: $message,
            code: (int) $rollbackFailure->getCode(),
            previousException: $context,
        );
    }
}
