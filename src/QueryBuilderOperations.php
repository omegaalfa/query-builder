<?php

declare(strict_types=1);


namespace Omegaalfa\QueryBuilder;

use Closure;
use Throwable;
use Omegaalfa\QueryBuilder\Enums\JoinType;
use Omegaalfa\QueryBuilder\Enums\OrderDirection;
use Omegaalfa\QueryBuilder\Enums\SqlOperator;
use Omegaalfa\QueryBuilder\Exceptions\QueryException;
use Omegaalfa\QueryBuilder\Exceptions\UnsupportedDatabaseFeatureException;
use Omegaalfa\QueryBuilder\Expressions\AliasedExpression;
use Omegaalfa\QueryBuilder\Interfaces\DatabaseValueInterface;
use Omegaalfa\QueryBuilder\Interfaces\SqlExpressionInterface;
use Omegaalfa\QueryBuilder\PostgreSQL\PgVector\Vector;
use Omegaalfa\QueryBuilder\PostgreSQL\PgVector\VectorDistance;
use Omegaalfa\QueryBuilder\PostgreSQL\PgVector\VectorMetric;
use Omegaalfa\QueryBuilder\Interfaces\QueryBuilderInterface;
use Omegaalfa\QueryBuilder\Traits\HelperQueryOperationsTrait;

class QueryBuilderOperations implements QueryBuilderInterface
{

    use HelperQueryOperationsTrait;

    /**
     * @var string
     */
    protected string $driver = 'mysql';

    /** @var array<int, mixed> */
    protected array $joins = [];

    /** @var array<int, mixed> */
    protected array $where = [];

    /** @var array<int, mixed> */
    protected array $orderBy = [];

    /** @var array<int, mixed> */
    protected array $groupBy = [];

    /** @var array<int, mixed> */
    protected array $having = [];

    /** @var array<int, mixed>|null */
    protected ?array $limit = null;

    protected bool $paginationRequested = false;

    /** @var list<string> */
    protected array $insertColumns = [];

    protected bool $batchInsert = false;

    /** @internal True only for SELECT operations built through structured read APIs. */
    protected bool $cacheEligibleRead = false;

    /** @internal Structured operation intent used for cache invalidation. */
    protected ?string $operationType = null;

    /**
     * @internal
     * @var list<string>
     */
    protected array $cacheTableDependencies = [];

    /** @internal */
    protected bool $cacheDependenciesIdentifiable = true;

    /**
     * @internal
     * @var array<int, array<string, mixed>>|null Original SQLite batch rows while bindings are deferred.
     */
    protected ?array $sqliteBatchRows = null;

    /** @internal Enables positional bindings only for an executing SQLite/MySQL batch. */
    protected bool $batchPositionalExecution = false;

    /** @var list<string>|null */
    protected ?array $conflictColumns = null;

    protected bool $conflictActionDefined = false;

    protected bool $returnLastInsertIdRequested = false;

    /** @var list<string> */
    protected array $returningColumns = [];

    /** @var array<string, mixed> */
    protected array $params = [];

    /** @var array<int, mixed> */
    protected array $sql = [];

    protected ?SqlCompilationContext $compilationContext = null;

    protected int $ctePlaceholderCounter = 0;

    /** @var array<string, mixed> */
    protected array $cteParams = [];

    /** @var array<int|string, mixed>|null */
    protected ?array $compiledQueryParams = null;


    /**
     * @var string|null
     */
    protected string|null $table = null;


    /**
     * @var array Para agrupar condições OR
     */
    /** @var array<int, mixed> */
    protected array $whereGroups = [];

    /** @var list<array{alias: string, sql: string}> */
    protected array $ctes = [];

    /** @var array{perPage: int, column: string, tieBreaker: string|null, direction: OrderDirection}|null */
    protected ?array $cursorPagination = null;


    /**
     * @var bool
     */
    protected bool $isOrGroup = false;

    /** @param array<int, string> $tags */
    public function cacheTags(array $tags): self
    {
        return $this;
    }

    /**
     * Adiciona uma CTE usando outro builder, uma closure de configuração ou SQL confiável.
     *
     * @param Closure(self): self|string|self $query
     * @param array<int|string, mixed> $params
     */
    public function with(string $alias, Closure|string|QueryBuilderInterface $query, array $params = []): self
    {
        $this->cacheEligibleRead = false;
        $this->assertSafeCteAlias($alias);
        $subquery = $query instanceof Closure ? $query(new self()) : $query;
        if (is_string($subquery)) {
            $subquery = $this->createCteRawBuilder($subquery, $params);
        }

        if (!$subquery instanceof self) {
            throw new QueryException('CTE query must use the QueryBuilder implementation.');
        }

        $subquery->driver = $this->driver;
        $sql = $subquery->getQuerySql();
        $placeholderMap = [];
        foreach ($subquery->currentQueryParams() as $placeholder => $value) {
            $internalPlaceholder = ':__cte' . $this->ctePlaceholderCounter++;
            $placeholderMap[$placeholder] = $internalPlaceholder;
            $this->cteParams[$internalPlaceholder] = $value;
        }
        if ($placeholderMap !== []) {
            $sql = $this->replaceSqlPlaceholders(
                $sql,
                static fn (string $candidate): ?string => $placeholderMap[$candidate] ?? null,
            );
        }

        $this->ctes[] = [
            'alias' => $this->quoteIdentifier($alias),
            'sql' => $sql,
        ];

        return $this;
    }

    /** @param array<int|string, mixed> $params */
    public function whereExists(Closure|string|QueryBuilderInterface $subquery, array $params = []): self
    {
        $this->where[] = 'EXISTS (' . $this->compileSubquery($subquery, $params) . ')';
        return $this;
    }

    /** @param array<int|string, mixed> $params */
    public function whereNotExists(Closure|string|QueryBuilderInterface $subquery, array $params = []): self
    {
        $this->where[] = 'NOT EXISTS (' . $this->compileSubquery($subquery, $params) . ')';
        return $this;
    }

    /** @param array<int|string, mixed> $params */
    public function whereInSubquery(
        string $column,
        Closure|string|QueryBuilderInterface $subquery,
        array $params = [],
    ): self {
        $this->where[] = sprintf(
            '%s IN (%s)',
            $this->quoteIdentifier($column),
            $this->compileSubquery($subquery, $params),
        );
        return $this;
    }

    /** @param array<int|string, mixed> $params */
    public function whereNotInSubquery(
        string $column,
        Closure|string|QueryBuilderInterface $subquery,
        array $params = [],
    ): self {
        $this->where[] = sprintf(
            '%s NOT IN (%s)',
            $this->quoteIdentifier($column),
            $this->compileSubquery($subquery, $params),
        );
        return $this;
    }

    /** @param array<int|string, mixed> $params */
    public function fromSubquery(
        string $alias,
        Closure|string|QueryBuilderInterface $subquery,
        array $params = [],
    ): self {
        $this->assertSafeCteAlias($alias);
        $this->resetOperationsState();
        $this->table = $this->quoteIdentifier($alias);
        $this->sql = [
            'SELECT * FROM (' . $this->compileSubquery($subquery, $params) . ') AS ' . $this->table,
        ];
        $this->cacheEligibleRead = false;
        return $this;
    }

    /** @param array<int|string, mixed> $params */
    private function compileSubquery(
        Closure|string|QueryBuilderInterface $query,
        array $params = [],
    ): string {
        $subquery = $query instanceof Closure ? $query(new self()) : $query;
        if (is_string($subquery)) {
            $builder = new self();
            $builder->raw($subquery, $params);
            $subquery = $builder;
        }
        if (!$subquery instanceof self) {
            throw new QueryException('Subquery must use the QueryBuilder implementation.');
        }

        $subquery->driver = $this->driver;
        $sql = $subquery->getQuerySql();
        foreach ($subquery->params as $placeholder => $value) {
            $sql = str_replace($placeholder, $this->bindValue($value), $sql);
        }
        return $sql;
    }

    private function assertSafeCteAlias(string $alias): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $alias)) {
            throw new QueryException('Invalid CTE alias: ' . $alias);
        }
    }

    public function __clone(): void
    {
        $this->params = [...$this->params];
        $this->compilationContext = null;
    }

    /**
     * Define um alias para a tabela principal da consulta.
     *
     * Exemplo: ->select('doenca')->alias('d')
     *
     * @param string $alias Alias a ser aplicado à tabela.
     * @return $this
     */
    public function alias(string $alias): self
    {
        $this->sql[] = "AS {$this->quoteIdentifier($alias)}";
        return $this;
    }


    /**
     * Inicia uma consulta SELECT.
     *
     * Aceita identificadores de colunas e expressões SQL (funções, aliases).
     *
     * ⚠️ IMPORTANTE: Para campos dinâmicos vindos de usuário, sempre use whitelist:
     *
     * ✅ SEGURO - Campos conhecidos:
     * ->select('users', ['id', 'name', 'email'])
     * ->select('orders', ['id', 'COUNT(*) as total'])
     *
     * ✅ SEGURO - Com whitelist:
     * $allowedFields = ['id', 'name', 'email'];
     * $fields = array_intersect($userFields, $allowedFields);
     * ->select('users', $fields)
     *
     * ❌ INSEGURO - Input direto do usuário:
     * ->select('users', $_GET['fields'])  // NUNCA FAÇA ISSO!
     *
     * @param string $table Nome da tabela ou view.
     * @param array $fields Lista de colunas a selecionar (default: ['*']).
     * @return $this
     */
    /** @param array<int, string> $fields */
    public function select(string $table, array $fields = ['*']): self
    {
        $this->resetOperationsState();
        $this->cacheEligibleRead = true;
        $this->table = $this->quoteIdentifier($table);
        $this->recordCacheTableDependency($table);

        // ✅ SECURITY FIX: Apply normalization/quoting to fields to prevent identifier injection
        // Uses normalizeField from HelperQueryOperationsTrait which allows functions/aliases but quotes identifiers
        $processedFields = array_map(
            fn (mixed $field): string => $field instanceof SqlExpressionInterface
                ? $field->compile($this->compilationContext())
                : $this->normalizeField($field),
            $fields,
        );

        $this->sql = ['SELECT', implode(', ', $processedFields), 'FROM ' . $this->table];
        return $this;
    }

    /** @param array<int, string> $expressions */
    public function selectRaw(string $table, string|array $expressions): self
    {
        $this->resetOperationsState();
        $this->cacheEligibleRead = true;
        $this->table = $this->quoteIdentifier($table);
        $this->recordCacheTableDependency($table);
        $fields = is_array($expressions) ? $expressions : [$expressions];
        if ($fields === []) {
            throw new QueryException('selectRaw() requires at least one expression.');
        }
        $this->sql = ['SELECT', implode(', ', $fields), 'FROM ' . $this->table];
        return $this;
    }

    /**
     * @return void
     */
    protected function resetOperationsState(bool $preserveCacheKey = false): void
    {
        $this->sql = [];
        $this->joins = [];
        $this->where = [];
        $this->orderBy = [];
        $this->groupBy = [];
        $this->having = [];
        $this->table = null;
        $this->cacheTableDependencies = [];
        $this->cacheDependenciesIdentifiable = true;
        $this->limit = null;
        $this->paginationRequested = false;
        $this->cursorPagination = null;
        $this->insertColumns = [];
        $this->batchInsert = false;
        $this->cacheEligibleRead = false;
        $this->operationType = null;
        $this->sqliteBatchRows = null;
        $this->batchPositionalExecution = false;
        $this->conflictColumns = null;
        $this->conflictActionDefined = false;
        $this->returnLastInsertIdRequested = false;
        $this->returningColumns = [];
        $this->params = [];
        $this->compiledQueryParams = null;
        $this->whereGroups = [];
        $this->isOrGroup = false;
        $this->compilationContext = null;
        if (!$preserveCacheKey && method_exists($this, 'resetQueryCacheKey')) {
            $this->resetQueryCacheKey();
        }
    }

    /**
     * Inicia uma operação INSERT.
     *
     * @param string $table Nome da tabela de destino.
     * @param array $data Array associativo coluna => valor.
     * @return $this
     */
    /** @param array<string, mixed> $data */
    public function insert(string $table, array $data): self
    {
        $this->resetOperationsState();
        $this->table = $this->quoteIdentifier($table);
        $this->recordCacheTableDependency($table);
        $this->operationType = 'INSERT';
        $fields = array_keys($data);
        $this->insertColumns = $fields;
        $quotedFields = array_map([$this, 'quoteIdentifier'], $fields);
        $placeholders = [];
        foreach ($data as $value) {
            $placeholder = $this->bindValue($value);
            $placeholders[] = $this->valueSql($placeholder, $value);
        }
        $this->sql = [
            'INSERT INTO',
            $this->table,
            '(' . implode(', ', $quotedFields) . ')',
            'VALUES',
            '(' . implode(', ', $placeholders) . ')'
        ];

        return $this;
    }


    /**
     * Insere múltiplos registros de uma vez (bulk insert)
     *
     * @param string $table
     * @param array $data Array de arrays associativos
     * @return $this
     * @throws QueryException
     */
    /** @param array<int, array<string, mixed>> $data */
    public function insertBatch(string $table, array $data): self
    {
        if (empty($data)) {
            throw new QueryException("Batch insert requires at least one row");
        }

        $this->resetOperationsState();
        $this->table = $this->quoteIdentifier($table);
        $this->recordCacheTableDependency($table);
        $this->operationType = 'INSERT';
        $this->batchInsert = true;

        // Pega colunas do primeiro registro
        $firstRow = reset($data);
        $fields = array_keys($firstRow);
        $this->insertColumns = $fields;

        // Valida que todos os registros têm as mesmas colunas
        foreach ($data as $index => $row) {
            if (array_keys($row) !== $fields) {
                throw new QueryException("All rows must have the same columns. Error at index {$index}");
            }
        }

        if ($this->driver === 'sqlite') {
            // Retain the row source; materialized bindings are created only when needed.
            $this->sqliteBatchRows = $data;
        }

        $quotedFields = array_map([$this, 'quoteIdentifier'], $fields);

        if ($this->driver === 'sqlite') {
            foreach ($data as $row) {
                foreach ($fields as $field) {
                    $value = $row[$field];
                    if ($value instanceof DatabaseValueInterface) {
                        $this->valueSql(':param', $value);
                    }
                }
            }
            $this->sql = ['INSERT INTO', $this->table, '(' . implode(', ', $quotedFields) . ')', 'VALUES'];
            return $this;
        }

        // Constrói placeholders
        $valueSets = [];
        $parameterIndex = 0;
        foreach ($data as $rowIndex => $row) {
            $placeholders = [];
            foreach ($fields as $field) {
                if ($this->driver === 'sqlite') {
                    $value = $row[$field];
                    if ($value instanceof DatabaseValueInterface && !$value->supportsDriver($this->driver)) {
                        throw new UnsupportedDatabaseFeatureException(
                            sprintf('%s is not supported by the %s driver.', $value::class, $this->driver),
                        );
                    }
                    $param = ':param' . $parameterIndex++;
                } else {
                    $param = $this->bindValue($row[$field]);
                }
                $placeholders[] = $this->valueSql($param, $row[$field]);
            }
            $valueSets[] = '(' . implode(', ', $placeholders) . ')';
        }

        $this->sql = [
            'INSERT INTO',
            $this->table,
            '(' . implode(', ', $quotedFields) . ')',
            'VALUES',
            implode(', ', $valueSets)
        ];

        return $this;
    }

    /**
     * Materializes bindings for the current operation without retaining a second SQLite batch representation.
     *
     * @return array<int|string, mixed>
     */
    protected function currentQueryParams(): array
    {
        if ($this->compiledQueryParams !== null) {
            return $this->compiledQueryParams;
        }

        if ($this->sqliteBatchRows === null) {
            return $this->cteParams + $this->params;
        }

        $params = [];
        $parameterIndex = 0;
        foreach ($this->sqliteBatchRows as $row) {
            foreach ($this->insertColumns as $field) {
                $params[':param' . $parameterIndex++] = $row[$field];
            }
        }

        return $this->cteParams + $params;
    }

    /** @return array<int|string, mixed> */
    protected function currentNamedQueryParams(): array
    {
        if (!$this->batchPositionalExecution) {
            return $this->currentQueryParams();
        }

        if ($this->sqliteBatchRows === null) {
            return $this->cteParams + $this->params;
        }

        $params = [];
        $parameterIndex = 0;
        foreach ($this->sqliteBatchRows as $row) {
            foreach ($this->insertColumns as $field) {
                $params[':param' . $parameterIndex++] = $row[$field];
            }
        }

        return $this->cteParams + $params;
    }

    /**
     * Compiles one SQLite batch range with an independent placeholder context.
     *
     * @internal
     * @return array{sql: string, params: array<string, mixed>}
     */
    protected function compileSQLiteBatchChunk(int $offset, int $length): array
    {
        if ($this->driver !== 'sqlite' || !$this->batchInsert || $this->sqliteBatchRows === null) {
            throw new QueryException('SQLite batch chunk compilation requires an active SQLite insertBatch().');
        }
        if ($offset < 0 || $length < 1 || $offset >= count($this->sqliteBatchRows)) {
            throw new QueryException('SQLite batch chunk range is invalid.');
        }

        $rows = array_slice($this->sqliteBatchRows, $offset, $length);
        $params = [];
        $context = new SqlCompilationContext(
            $params,
            $this->driver,
            fn (string $identifier): string => $this->quoteIdentifier($identifier),
        );
        $quotedFields = array_map([$this, 'quoteIdentifier'], $this->insertColumns);
        $valueSets = [];

        foreach ($rows as $row) {
            $placeholders = [];
            foreach ($this->insertColumns as $field) {
                $placeholder = $context->bind($row[$field]);
                $placeholders[] = $this->valueSql($placeholder, $row[$field]);
            }
            $valueSets[] = '(' . implode(', ', $placeholders) . ')';
        }

        return [
            'sql' => implode(' ', [
                'INSERT INTO',
                $this->table,
                '(' . implode(', ', $quotedFields) . ')',
                'VALUES',
                implode(', ', $valueSets),
            ]),
            'params' => $params,
        ];
    }

    /**
     * Explicitly requests the generated identifier.
     *
     * PostgreSQL uses RETURNING "id" and never PDO::lastInsertId(). MySQL and
     * SQLite use their native PDO last-insert-id implementation.
     */
    public function returnLastInsertId(): self
    {
        $this->assertActiveInsert('returnLastInsertId');
        if ($this->returningColumns !== []) {
            throw new QueryException('returnLastInsertId() cannot be combined with returning().');
        }

        $this->returnLastInsertIdRequested = true;
        if ($this->driver === 'pgsql') {
            $this->returningColumns = ['id'];
        }

        return $this;
    }

    /** @param list<string> $columns */
    public function returning(array $columns): self
    {
        if ($this->driver === 'pgsql') {
            $this->assertActiveInsert('returning');
        } elseif ($this->driver === 'sqlite') {
            $operation = $this->sql[0] ?? null;
            $isSingleInsert = $this->insertColumns !== [] && !$this->batchInsert;
            $isUpdateOrDelete = $operation === 'UPDATE' || $operation === 'DELETE FROM';
            if (!$isSingleInsert && !$isUpdateOrDelete) {
                throw new QueryException('returning() requires an active SQLite INSERT, UPDATE, or DELETE operation.');
            }
        } else {
            throw new UnsupportedDatabaseFeatureException('RETURNING is currently supported only by the pgsql driver.');
        }
        if ($this->returnLastInsertIdRequested) {
            throw new QueryException('returning() cannot be combined with returnLastInsertId().');
        }
        if ($columns === [] || !array_is_list($columns)) {
            throw new QueryException('Returning columns must be a non-empty list.');
        }
        foreach ($columns as $column) {
            if (!is_string($column) || $column === '') {
                throw new QueryException('Returning columns must contain only non-empty identifiers.');
            }
        }
        $this->returningColumns = $columns;

        return $this;
    }

    /**
     * Define as colunas da restrição de conflito para um INSERT PostgreSQL.
     *
     * @param list<string> $columns
     */
    public function onConflict(array $columns): self
    {
        if ($this->driver !== 'pgsql' && $this->driver !== 'sqlite') {
            throw new UnsupportedDatabaseFeatureException('ON CONFLICT is currently supported only by the pgsql driver.');
        }
        if ($this->driver === 'sqlite' && $this->batchInsert) {
            throw new UnsupportedDatabaseFeatureException('SQLite ON CONFLICT is not enabled for insertBatch().');
        }
        if ($this->insertColumns === []) {
            throw new QueryException('onConflict() requires an active insert or insertBatch operation.');
        }
        if ($columns === [] || !array_is_list($columns)) {
            throw new QueryException('Conflict columns must be a non-empty list.');
        }
        $this->assertInsertedColumns($columns, 'conflict');
        $this->conflictColumns = $columns;

        return $this;
    }

    public function doNothing(): self
    {
        $this->assertConflictActionCanBeDefined();
        $this->sql[] = sprintf(
            'ON CONFLICT (%s) DO NOTHING',
            implode(', ', array_map([$this, 'quoteIdentifier'], $this->conflictColumns)),
        );
        $this->conflictActionDefined = true;

        return $this;
    }

    /** @param list<string> $columns */
    public function doUpdate(array $columns): self
    {
        $this->assertConflictActionCanBeDefined();
        if ($columns === [] || !array_is_list($columns)) {
            throw new QueryException('Update columns must be a non-empty list.');
        }
        $this->assertInsertedColumns($columns, 'update');
        $assignments = array_map(
            fn (string $column): string => sprintf(
                '%s = EXCLUDED.%s',
                $this->quoteIdentifier($column),
                $this->quoteIdentifier($column),
            ),
            $columns,
        );
        $this->sql[] = sprintf(
            'ON CONFLICT (%s) DO UPDATE SET %s',
            implode(', ', array_map([$this, 'quoteIdentifier'], $this->conflictColumns)),
            implode(', ', $assignments),
        );
        $this->conflictActionDefined = true;

        return $this;
    }

    /** @param list<string> $columns */
    private function assertInsertedColumns(array $columns, string $role): void
    {
        foreach ($columns as $column) {
            if (!is_string($column) || !in_array($column, $this->insertColumns, true)) {
                throw new QueryException(sprintf(
                    'Invalid %s column "%s": it must be present in the inserted rows.',
                    $role,
                    is_scalar($column) ? (string) $column : get_debug_type($column),
                ));
            }
        }
    }

    private function assertConflictActionCanBeDefined(): void
    {
        if ($this->conflictColumns === null) {
            throw new QueryException('Define onConflict() before choosing a conflict action.');
        }
        if ($this->conflictActionDefined) {
            throw new QueryException('A conflict action has already been defined.');
        }
    }

    private function assertActiveInsert(string $method): void
    {
        if ($this->insertColumns === []) {
            throw new QueryException("{$method}() requires an active insert or insertBatch operation.");
        }
    }

    /**
     * Inicia uma operação UPDATE.
     *
     * @param string $table Nome da tabela.
     * @param array $data Array associativo coluna => novo valor.
     * @return $this
     */
    /** @param array<string, mixed> $data */
    public function update(string $table, array $data): self
    {
        $this->resetOperationsState();
        $assignments = [];
        $this->table = $this->quoteIdentifier($table);
        $this->recordCacheTableDependency($table);
        $this->operationType = 'UPDATE';
        foreach ($data as $key => $value) {
            $param = $this->bindValue($value);
            $assignments[] = sprintf('%s = %s', $this->quoteIdentifier($key), $this->valueSql($param, $value));
        }

        $this->sql = ['UPDATE', $this->table, 'SET', implode(', ', $assignments)];
        return $this;
    }

    /**
     * Inicia uma operação DELETE.
     *
     * @param string $table Nome da tabela.
     * @return $this
     */
    public function delete(string $table): self
    {
        $this->resetOperationsState();
        $this->table = $this->quoteIdentifier($table);
        $this->recordCacheTableDependency($table);
        $this->operationType = 'DELETE';
        $this->sql = ['DELETE FROM', $this->table];
        return $this;
    }

    /**
     * Adiciona uma condição WHERE.
     *
     * Exemplo: ->where('status', SqlOperator::EQUALS, 1)
     *
     * @param string $column Coluna a comparar.
     * @param SqlOperator|string $operator Operador lógico (EQUALS, LIKE, etc).
     * @param mixed $value Valor de comparação.
     * @return $this
     * @throws QueryException
     */
    public function where(string $column, SqlOperator|string $operator, mixed $value): self
    {
        if ($this->isOrGroup) {
            $this->where[] = '(' . implode(' OR ', $this->whereGroups) . ')';
            $this->whereGroups = [];
            $this->isOrGroup = false;
        }

        $operator = $this->normalizeAndValidateOperator($operator);
        $param = $this->bindValue($value);
        $this->where[] = sprintf('%s %s %s', $this->quoteIdentifier($column), $operator->value, $param);

        return $this;
    }

    /**
     * Adiciona uma condição OR WHERE.
     *
     * Exemplo: ->orWhere('nome', 'LIKE', '%botulismo%')
     *
     * @param string $column Coluna de comparação.
     * @param SqlOperator|string $operator Operador (ex: '=', 'LIKE', etc).
     * @param mixed $value Valor de comparação.
     * @return $this
     * @throws QueryException
     */
    public function orWhere(string $column, SqlOperator|string $operator, mixed $value): self
    {
        $operator = $this->normalizeAndValidateOperator($operator);
        $param = $this->bindValue($value);
        $condition = sprintf('%s %s %s', $this->quoteIdentifier($column), $operator->value, $param);

        // Marca que estamos em grupo OR
        if (!$this->isOrGroup && !empty($this->where)) {
            $this->isOrGroup = true;
            $this->whereGroups[] = array_pop($this->where);
        }

        $this->whereGroups[] = $condition;

        return $this;
    }

    public function whereGroup(Closure $callback): self
    {
        $outerWhere = $this->where;
        $outerGroups = $this->whereGroups;
        $outerOrGroup = $this->isOrGroup;

        $this->where = [];
        $this->whereGroups = [];
        $this->isOrGroup = false;

        try {
            $callback($this);
            $this->flushOrGroup();
            if ($this->where === []) {
                throw new QueryException('whereGroup() callback must add at least one condition.');
            }
            $group = count($this->where) === 1
                && str_starts_with($this->where[0], '(')
                && str_ends_with($this->where[0], ')')
                ? $this->where[0]
                : '(' . implode(' AND ', $this->where) . ')';
        } catch (Throwable $exception) {
            $this->where = $outerWhere;
            $this->whereGroups = $outerGroups;
            $this->isOrGroup = $outerOrGroup;
            throw $exception;
        }

        $this->where = $outerWhere;
        $this->whereGroups = $outerGroups;
        $this->isOrGroup = $outerOrGroup;
        $this->where[] = $group;
        return $this;
    }

    /**
     * Adiciona uma condição WHERE ... IN (...).
     *
     * Exemplo: ->whereIn('status', [1, 2, 3])
     *
     * @param string $column Coluna alvo.
     * @param array $values Lista de valores.
     * @return $this
     * @throws QueryException
     */
    /** @param array<int, mixed> $values */
    public function whereIn(string $column, array $values): self
    {
        if (empty($values)) {
            throw new QueryException("Values for IN must be a non-empty array.");
        }

        $placeholders = [];
        foreach ($values as $v) {
            $param = $this->bindValue($v);
            $placeholders[] = $param;
        }

        $this->where[] = sprintf('%s IN (%s)', $this->quoteIdentifier($column), implode(', ', $placeholders));
        return $this;
    }

    /**
     * Adiciona uma condição WHERE ... NOT IN (...).
     *
     * @param string $column Coluna alvo.
     * @param array $values Lista de valores.
     * @return $this
     * @throws QueryException
     */
    /** @param array<int, mixed> $values */
    public function whereNotIn(string $column, array $values): self
    {
        if (empty($values)) {
            throw new QueryException("Values for NOT IN must be a non-empty array.");
        }

        $placeholders = [];
        foreach ($values as $v) {
            $param = $this->bindValue($v);
            $placeholders[] = $param;
        }

        $this->where[] = sprintf('%s NOT IN (%s)', $this->quoteIdentifier($column), implode(', ', $placeholders));
        return $this;
    }

    /**
     * Adiciona uma condição WHERE ... BETWEEN ... AND ...
     *
     * @param string $column Coluna alvo.
     * @param array $range Array com dois valores [min, max].
     * @return $this
     * @throws QueryException
     */
    /** @param array<int, mixed> $range */
    public function whereBetween(string $column, array $range): self
    {
        if (count($range) !== 2) {
            throw new QueryException("BETWEEN requires an array with 2 values.");
        }
        $param1 = $this->bindValue($range[0]);
        $param2 = $this->bindValue($range[1]);

        $this->where[] = sprintf('%s BETWEEN %s AND %s', $this->quoteIdentifier($column), $param1, $param2);

        return $this;
    }

    /**
     * Adiciona uma condição WHERE ... NOT BETWEEN ... AND ...
     *
     * @param string $column Coluna alvo.
     * @param array $range Array com dois valores [min, max].
     * @return $this
     * @throws QueryException
     */
    /** @param array<int, mixed> $range */
    public function whereNotBetween(string $column, array $range): self
    {
        if (count($range) !== 2) {
            throw new QueryException("NOT BETWEEN requires an array with 2 values.");
        }
        $param1 = $this->bindValue($range[0]);
        $param2 = $this->bindValue($range[1]);

        $this->where[] = sprintf('%s NOT BETWEEN %s AND %s', $this->quoteIdentifier($column), $param1, $param2);

        return $this;
    }

    /**
     * Adiciona uma condição WHERE ... IS NULL.
     *
     * @param string $column Coluna alvo.
     * @return $this
     */
    public function whereNull(string $column): self
    {
        $this->where[] = $this->quoteIdentifier($column) . ' IS NULL';
        return $this;
    }

    /**
     * Adiciona uma condição WHERE ... IS NOT NULL.
     *
     * @param string $column Coluna alvo.
     * @return $this
     */
    public function whereNotNull(string $column): self
    {
        $this->where[] = $this->quoteIdentifier($column) . ' IS NOT NULL';
        return $this;
    }

    /**
     * Cria uma junção (JOIN) entre tabelas.
     *
     * Exemplo: ->join('doenca_comercial', 'doenca_comercial.idDoenca', '=', 'doenca.idDoenca', JoinType::LEFT)
     *
     * @param string $table Tabela a ser unida.
     * @param string $key Coluna da tabela atual.
     * @param string $operator Operador de comparação (=, <>, etc).
     * @param string $refer Coluna da tabela relacionada.
     * @param JoinType $type Tipo da junção (INNER, LEFT, RIGHT, FULL).
     * @return $this
     * @throws QueryException
     */
    public function join(string $table, string $key, string $operator, string $refer, JoinType $type = JoinType::INNER): self
    {
        // Valida operador para prevenir SQL injection via operator
        $validOperators = ['=', '<>', '!=', '>', '<', '>=', '<='];
        if (!in_array($operator, $validOperators, true)) {
            throw new QueryException(
                "Invalid JOIN operator: {$operator}. " .
                "Allowed operators: " . implode(', ', $validOperators)
            );
        }

        // ⚙️ MySQL não suporta FULL JOIN → usar emulação
        if ($type === JoinType::FULL && in_array($this->driver, ['mysql', 'mariadb'])) {
            throw new QueryException(
                "FULL JOIN não é suportado nativamente pelo MySQL/MariaDB. " .
                "Para esta funcionalidade, a query deve ser construída manualmente usando UNION."
            );
        }

        // ✅ Padrão para todos os demais casos
        $this->joins[] = sprintf(
            '%s %s ON %s %s %s',
            $type->value,
            $this->quoteIdentifier($table),
            $this->quoteIdentifier($key),
            $operator,
            $this->quoteIdentifier($refer)
        );
        $this->recordCacheTableDependency($table);

        return $this;
    }

    /** @internal */
    private function recordCacheTableDependency(string $table): void
    {
        $parts = preg_split('/\s+AS\s+/i', trim($table));
        if ($parts === false || count($parts) > 2) {
            $this->cacheDependenciesIdentifiable = false;
            return;
        }
        if (isset($parts[1]) && $this->decodeCacheIdentifierPart(trim($parts[1])) === null) {
            $this->cacheDependenciesIdentifiable = false;
            return;
        }
        $normalized = [];
        foreach (explode('.', trim($parts[0])) as $part) {
            $decoded = $this->decodeCacheIdentifierPart(trim($part));
            if ($decoded === null) {
                $this->cacheDependenciesIdentifiable = false;
                return;
            }
            $normalized[] = strtolower($decoded);
        }
        if ($this->driver === 'sqlite' && count($normalized) > 1 && $normalized[0] === 'main') {
            array_shift($normalized);
        }
        $dependency = implode('.', $normalized);
        if (!in_array($dependency, $this->cacheTableDependencies, true)) {
            $this->cacheTableDependencies[] = $dependency;
        }
    }

    private function decodeCacheIdentifierPart(string $part): ?string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_$]*$/D', $part) === 1) {
            return $part;
        }
        foreach ([['"', '"'], ['`', '`']] as [$open, $close]) {
            if (strlen($part) >= 2 && $part[0] === $open && substr($part, -1) === $close) {
                $decoded = str_replace($open . $close, $close, substr($part, 1, -1));
                if ($decoded !== '' && !str_contains($decoded, '.')) {
                    return $decoded;
                }
            }
        }
        return null;
    }

    /**
     * Define a ordenação da consulta.
     *
     * Exemplo: ->orderBy('idDoenca', OrderDirection::DESC)
     * @param string $column
     * @param OrderDirection $direction
     *
     * @return $this
     */
    public function orderBy(string $column, OrderDirection $direction = OrderDirection::ASC): self
    {
        $this->orderBy[] = sprintf('%s %s', $this->quoteIdentifier($column), $direction->value);
        return $this;
    }

    public function orderByExpression(
        SqlExpressionInterface $expression,
        OrderDirection $direction = OrderDirection::ASC,
    ): self {
        $this->orderBy[] = sprintf('%s %s', $expression->compile($this->compilationContext()), $direction->value);
        return $this;
    }

    /** @param Vector|array<int, int|float> $vector */
    public function nearestNeighbors(
        string $column,
        Vector|array $vector,
        VectorMetric $metric = VectorMetric::L2,
        string $alias = 'distance',
        ?int $dimensions = null,
    ): self {
        $distance = new VectorDistance($column, $vector, $metric, $dimensions);
        $selected = new AliasedExpression($distance, $alias);
        $this->sql[1] = ($this->sql[1] ?? '*') . ', ' . $selected->compile($this->compilationContext());
        return $this->orderByExpression($distance, OrderDirection::ASC);
    }

    protected function compilationContext(): SqlCompilationContext
    {
        return $this->compilationContext ??= new SqlCompilationContext(
            $this->params,
            $this->driver,
            fn (string $identifier): string => $this->quoteIdentifier($identifier),
        );
    }

    protected function valueSql(string $placeholder, mixed $value): string
    {
        if (!$value instanceof DatabaseValueInterface) {
            return $placeholder;
        }
        if (!$value->supportsDriver($this->driver)) {
            throw new UnsupportedDatabaseFeatureException(
                sprintf('%s is not supported by the %s driver.', $value::class, $this->driver)
            );
        }
        $type = $value->sqlType();
        return $type === null ? $placeholder : sprintf('CAST(%s AS %s)', $placeholder, $type);
    }

    protected function bindValue(mixed $value): string
    {
        return $this->compilationContext()->bind($value);
    }

    /**
     * Define uma coluna ou expressão para agrupamento (GROUP BY).
     *
     * @param string $column Coluna ou expressão a agrupar.
     * @return $this
     */
    public function groupBy(string $column): self
    {
        $this->groupBy[] = $this->quoteIdentifier($column);
        return $this;
    }

    /**
     * Adiciona uma cláusula HAVING com operador e valor.
     *
     * Usada em conjunto com GROUP BY para filtrar agregações.
     *
     * Exemplo: ->having('COUNT(id)', SqlOperator::GREATER_THAN, 10)
     *
     * @param string $column Coluna ou expressão agregada.
     * @param SqlOperator $operator Operador lógico.
     * @param mixed $value Valor de comparação.
     * @return $this
     * @throws QueryException
     */
    public function having(string $column, SqlOperator $operator, mixed $value): self
    {
        if (empty($this->groupBy)) {
            throw new QueryException('HAVING clause requires GROUP BY');
        }

        // garante que não reinicia params ou state indevidamente
        $param = $this->bindValue($value);
        $this->having[] = sprintf('%s %s %s', $this->normalizeField($column), $operator->value, $param);

        return $this;
    }

    /**
     * Adiciona uma cláusula HAVING em formato bruto.
     *
     * ⚠️ ADVANCED API - Use apenas com SQL confiável!
     *
     * Para condições simples, prefira having() com parâmetros.
     * Use havingRaw() apenas para expressões SQL complexas.
     *
     * ✅ SEGURO - Expressão estática:
     * ->havingRaw('COUNT(*) > 5')
     * ->havingRaw('SUM(price) > AVG(price)')
     *
     * ❌ INSEGURO - Input de usuário:
     * ->havingRaw($_GET['condition'])  // NUNCA FAÇA ISSO!
     *
     * @param string $condition Condição completa em SQL.
     * @return $this
     * @throws QueryException
     */
    public function havingRaw(string $condition): self
    {
        if (empty($this->groupBy)) {
            throw new QueryException('HAVING clause requires GROUP BY');
        }
        $this->having[] = $condition; // append
        return $this;
    }

    /**
     * Define o limite de resultados e, opcionalmente, o deslocamento.
     *
     * Exemplo: ->limit(10, 5)
     *
     * @param int $limit Quantidade máxima de registros.
     * @param int $offset Posição inicial do cursor (default: 0).
     * @return $this
     */
    public function limit(int $limit, int $offset = 0): self
    {
        if ($limit < 1 || $offset < 0) {
            throw new QueryException('Limit must be greater than zero and offset cannot be negative.');
        }
        $this->limit = [$limit, $offset];
        $this->paginationRequested = false;

        return $this;
    }

    /**
     * Solicita paginação completa, incluindo a consulta COUNT.
     */
    public function paginate(int $perPage, int $currentPage = 1): self
    {
        if ($perPage < 1 || $currentPage < 1) {
            throw new QueryException('Per page and current page must be greater than zero.');
        }
        $this->limit = [$perPage, ($currentPage - 1) * $perPage];
        $this->paginationRequested = true;

        return $this;
    }

    public function paginateCursor(
        int $perPage,
        mixed $after = null,
        string $column = 'id',
        OrderDirection $direction = OrderDirection::ASC,
        ?string $tieBreaker = null,
    ): self
    {
        if ($perPage < 1) {
            throw new QueryException('Cursor page size must be greater than zero.');
        }
        foreach ([$column, $tieBreaker] as $cursorColumn) {
            if ($cursorColumn !== null && !preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/D', $cursorColumn)) {
                throw new QueryException('Invalid cursor column: ' . $cursorColumn);
            }
        }
        if (is_string($after) && $after !== '') {
            $decoded = base64_decode($after, true);
            if ($decoded === false) {
                throw new QueryException('Invalid cursor.');
            }
            $after = json_decode($decoded, true);
            if (json_last_error() !== JSON_ERROR_NONE || (!is_int($after) && !is_float($after) && !is_string($after) && !is_array($after))) {
                throw new QueryException('Invalid cursor.');
            }
        }
        if ($tieBreaker !== null && $after !== null && (!is_array($after) || count($after) !== 2)) {
            throw new QueryException('A composite cursor must contain both cursor values.');
        }
        if ($tieBreaker === null && is_array($after)) {
            throw new QueryException('A tie-breaker column is required for a composite cursor.');
        }
        $this->cursorPagination = [
            'perPage' => $perPage,
            'column' => $column,
            'tieBreaker' => $tieBreaker,
            'direction' => $direction,
        ];
        if ($after !== null) {
            $operator = $direction === OrderDirection::ASC ? SqlOperator::GREATER_THAN : SqlOperator::LESS_THAN;
            if ($tieBreaker === null) {
                $this->where($column, $operator, $after);
            } else {
                $this->flushOrGroup();
                $primaryValue = $after[0];
                $tieBreakerValue = $after[1];
                $primaryParam = $this->bindValue($primaryValue);
                $tieBreakerParam = $this->bindValue($tieBreakerValue);
                $primaryIdentifier = $this->quoteIdentifier($column);
                $tieBreakerIdentifier = $this->quoteIdentifier($tieBreaker);
                $this->where[] = sprintf(
                    '(%s %s %s OR (%s = %s AND %s %s %s))',
                    $primaryIdentifier,
                    $operator->value,
                    $primaryParam,
                    $primaryIdentifier,
                    $primaryParam,
                    $tieBreakerIdentifier,
                    $operator->value,
                    $tieBreakerParam,
                );
            }
        }
        $this->orderBy($column, $direction);
        if ($tieBreaker !== null) {
            $this->orderBy($tieBreaker, $direction);
        }
        $this->limit($perPage + 1);
        return $this;
    }

    /**
     * Define uma consulta SQL manual (raw query), opcionalmente com parâmetros.
     *
     * ⚠️ ADVANCED API - Use apenas com SQL confiável!
     *
     * Este método é um "escape hatch" para queries complexas que não podem
     * ser expressas com a API fluente (CTEs, window functions, etc).
     *
     * ✅ SEGURO - SQL estático:
     * ->raw('SELECT * FROM users WHERE created_at > ?', [date('Y-m-d')])
     *
     * ❌ INSEGURO - Input de usuário:
     * ->raw($_POST['query'])  // NUNCA FAÇA ISSO!
     *
     * @param string $query SQL completo (use ? ou :name para placeholders).
     * @param array $params Parâmetros para binding.
     * @return $this
     */
    /** @param array<string, mixed> $params */
    public function raw(string $query, array $params = []): self
    {
        $this->resetOperationsState();
        $this->sql = [$query];

        // Normaliza params para usar named placeholders
        if (!empty($params)) {
            $normalizedParams = [];

            // Se params são numéricos (? placeholders)
            if (array_is_list($params)) {
                // Converte ? para :param0, :param1, etc
                $paramCounter = 0;
                $normalizedQuery = preg_replace_callback('/\?/', function () use (&$paramCounter, $params) {
                    $placeholder = $this->bindValue($params[$paramCounter] ?? null);
                    $paramCounter++;
                    return $placeholder;
                }, $query);

                $this->sql = [$normalizedQuery];
            } else {
                // Params já estão nomeados
                $normalizedQuery = preg_replace_callback(
                    '/(?<!:):([A-Za-z_][A-Za-z0-9_]*)/',
                    function (array $match) use ($params): string {
                        $name = $match[1];
                        $key = array_key_exists($name, $params) ? $name : ':' . $name;
                        return array_key_exists($key, $params)
                            ? $this->bindValue($params[$key])
                            : $match[0];
                    },
                    $query,
                );
                $this->sql = [$normalizedQuery ?? $query];
            }
        }

        return $this;
    }

    /**
     * Retorna a string SQL final construída pelo QueryBuilder.
     *
     * Exemplo de retorno:
     *   SELECT * FROM doenca WHERE status = ? ORDER BY nome ASC LIMIT 10
     *
     * @return string Consulta SQL gerada.
     */
    public function getQuerySql(): string
    {
        $query = $this->sql;

        $positionalBatchParams = null;
        if ($this->driver === 'sqlite' && $this->batchInsert && $this->sqliteBatchRows !== null) {
            $valueSets = [];
            $parameterIndex = 0;
            foreach ($this->sqliteBatchRows as $row) {
                $placeholders = [];
                foreach ($this->insertColumns as $field) {
                    $value = $row[$field];
                    $placeholder = $this->batchPositionalExecution ? '?' : ':param' . $parameterIndex++;
                    $placeholders[] = $this->valueSql($placeholder, $row[$field]);
                    if ($this->batchPositionalExecution) {
                        $positionalBatchParams[] = $value;
                    }
                }
                $valueSets[] = '(' . implode(', ', $placeholders) . ')';
            }
            $query[] = implode(', ', $valueSets);
        }

        if ($this->joins) {
            $query[] = implode(' ', $this->joins);
        }

        // Fecha grupo OR pendente como sub-condição (liga por AND implícito)
        $this->flushOrGroup();

        if ($this->where) {
            // Simples e robusto: Tudo ligado por AND (incluindo grupos OR)
            $query[] = 'WHERE ' . implode(' AND ', $this->where);
        }

        if ($this->groupBy) {
            $query[] = 'GROUP BY ' . implode(', ', $this->groupBy);
        }

        if ($this->having) {
            $query[] = 'HAVING ' . implode(' AND ', $this->having);
        }

        if ($this->returningColumns !== []) {
            $query[] = 'RETURNING ' . implode(', ', array_map([$this, 'quoteIdentifier'], $this->returningColumns));
        }

        if ($this->orderBy) {
            $query[] = 'ORDER BY ' . implode(', ', $this->orderBy);
        }

        if ($this->limit && is_array($this->limit) && isset($this->limit[0])) {
            $limitVal = (int)$this->limit[0];
            $offsetVal = (int)($this->limit[1] ?? 0);

            if ($limitVal > 0) {
                $query[] = match ($this->driver) {
                    'pgsql', 'sqlite' => "LIMIT {$limitVal} OFFSET {$offsetVal}",
                    'sqlsrv', 'oci' => "OFFSET {$offsetVal} ROWS FETCH NEXT {$limitVal} ROWS ONLY",
                    default => "LIMIT {$offsetVal} , {$limitVal}"  // MySQL/MariaDB
                };
            }
        }

        // Reset states pra evitar vazamentos em chains múltiplas
        $this->whereGroups = [];
        $this->isOrGroup = false;

        $compiled = implode(' ', $query);
        if ($this->ctes !== []) {
            $compiled = 'WITH ' . implode(', ', array_map(
                static fn (array $cte): string => sprintf('%s AS (%s)', $cte['alias'], $cte['sql']),
                $this->ctes,
            )) . ' ' . $compiled;
        }

        $operationParams = $this->sqliteBatchRows === null ? $this->params : $this->currentQueryParams();
        if ($this->sqliteBatchRows !== null) {
            $operationParams = array_diff_key($operationParams, $this->cteParams);
        }
        $sourceParams = $this->cteParams + $operationParams;
        $compiledParams = [];

        if ($this->batchPositionalExecution) {
            $batchParams = $positionalBatchParams ?? [];
            if ($this->sqliteBatchRows !== null && $this->cteParams === []) {
                foreach ($batchParams as $index => $value) {
                    $compiledParams[$index + 1] = $value;
                }
                $this->compiledQueryParams = $compiledParams;
                return $compiled;
            }

            $batchIndex = 0;
            $compiled = $this->replaceSqlPlaceholders(
                $compiled,
                function (string $placeholder) use ($batchParams, $sourceParams, &$batchIndex, &$compiledParams): ?string {
                    if ($placeholder === '?') {
                        $compiledParams[] = $batchParams[$batchIndex++] ?? null;
                        return '?';
                    }
                    if (array_key_exists($placeholder, $sourceParams)) {
                        $compiledParams[] = $sourceParams[$placeholder];
                        return '?';
                    }
                    return null;
                },
            );
            $compiledParams = $compiledParams === []
                ? []
                : array_combine(range(1, count($compiledParams)), $compiledParams);
            $this->compiledQueryParams = $compiledParams;
            return $compiled;
        }

        if ($sourceParams === []) {
            $this->compiledQueryParams = $compiledParams;
            return $compiled;
        }

        // Structured bindings already use the final internal :paramN names.
        // CTEs still require scanning to remap their isolated parameters.
        $canUseInternalBindingFastPath = $this->ctes === [];
        if ($canUseInternalBindingFastPath) {
            $sourcePlaceholders = array_keys($sourceParams);
            $occurrences = [];
            foreach ($sourcePlaceholders as $placeholder) {
                $placeholder = (string) $placeholder;
                if (substr_count($compiled, $placeholder) !== 1
                    || ($position = strpos($compiled, $placeholder)) === false) {
                    $canUseInternalBindingFastPath = false;
                    break;
                }
                $occurrences[$position] = $placeholder;
            }
            if ($canUseInternalBindingFastPath) {
                ksort($occurrences);
                $canUseInternalBindingFastPath = array_values($occurrences) === $sourcePlaceholders;
            }
        }
        if ($canUseInternalBindingFastPath) {
            $this->compiledQueryParams = $sourceParams;
            return $compiled;
        }

        $compiled = $this->replaceSqlPlaceholders(
            $compiled,
            function (string $placeholder) use ($sourceParams, &$compiledParams): ?string {
                if (!array_key_exists($placeholder, $sourceParams)) {
                    return null;
                }
                $compiledPlaceholder = ':param' . count($compiledParams);
                $compiledParams[$compiledPlaceholder] = $sourceParams[$placeholder];
                return $compiledPlaceholder;
            },
        );
        $this->compiledQueryParams = $compiledParams;

        return $compiled;
    }

    protected function flushOrGroup(): void
    {
        if ($this->isOrGroup && $this->whereGroups !== []) {
            $this->where[] = '(' . implode(' OR ', $this->whereGroups) . ')';
            $this->whereGroups = [];
            $this->isOrGroup = false;
        }
    }

    /** Clears only bindings and placeholders retained for completed CTEs. */
    protected function clearCteBindings(): void
    {
        $this->ctes = [];
        $this->cteParams = [];
        $this->compiledQueryParams = null;
        $this->ctePlaceholderCounter = 0;
    }

    /**
     * Replaces SQL placeholders outside quoted text, identifiers and comments.
     *
     * @param callable(string): ?string $replacement
     */
    protected function replaceSqlPlaceholders(string $sql, callable $replacement): string
    {
        $result = '';
        $state = 'normal';
        $quote = '';
        $quotedBackslashEscapes = false;
        $length = strlen($sql);

        for ($index = 0; $index < $length; $index++) {
            if ($state === 'normal') {
                $span = strcspn($sql, "'\"`[-/#?\$:", $index);
                if ($span > 0) {
                    $result .= substr($sql, $index, $span);
                    $index += $span - 1;
                    continue;
                }
            }

            $character = $sql[$index];
            $next = $sql[$index + 1] ?? '';

            if ($state === 'line_comment') {
                $result .= $character;
                if ($character === "\n" || $character === "\r") {
                    $state = 'normal';
                }
                continue;
            }
            if ($state === 'block_comment') {
                $result .= $character;
                if ($character === '*' && $next === '/') {
                    $result .= $next;
                    $index++;
                    $state = 'normal';
                }
                continue;
            }
            if ($state === 'quoted') {
                $result .= $character;
                if ($character === '\\' && $next !== '' && $quotedBackslashEscapes) {
                    $result .= $next;
                    $index++;
                    continue;
                }
                if ($quote === '[') {
                    if ($character === ']') {
                        if ($next === ']') {
                            $result .= $next;
                            $index++;
                        } else {
                            $state = 'normal';
                        }
                    }
                    continue;
                }
                if ($character === $quote) {
                    if ($next === $quote) {
                        $result .= $next;
                        $index++;
                    } else {
                        $state = 'normal';
                    }
                }
                continue;
            }

            $dashComment = $character === '-' && $next === '-'
                && ($this->driver !== 'mysql' || ($sql[$index + 2] ?? ' ') <= ' ');
            $hashComment = $character === '#' && $this->driver === 'mysql';
            if ($dashComment || $hashComment) {
                $result .= $character . ($hashComment ? '' : $next);
                if ($dashComment) {
                    $index++;
                }
                $state = 'line_comment';
                continue;
            }
            if ($character === '/' && $next === '*') {
                $result .= '/*';
                $index++;
                $state = 'block_comment';
                continue;
            }
            if ($character === "'" || $character === '"' || $character === '`' || $character === '[') {
                $result .= $character;
                $state = 'quoted';
                $quote = $character;
                $previous = $sql[$index - 1] ?? '';
                $beforePrevious = $sql[$index - 2] ?? '';
                $postgresEscapeString = $this->driver === 'pgsql'
                    && $character === "'"
                    && ($previous === 'E' || $previous === 'e')
                    && ($beforePrevious === '' || preg_match('/[^A-Za-z0-9_]/', $beforePrevious) === 1);
                $quotedBackslashEscapes = $this->driver === 'mysql' || $postgresEscapeString;
                continue;
            }
            if ($character === '$' && $this->driver === 'pgsql') {
                $delimiter = $this->postgresDollarQuoteDelimiter($sql, $index);
                if ($delimiter !== null) {
                    $result .= $delimiter;
                    $index += strlen($delimiter) - 1;
                    $end = strpos($sql, $delimiter, $index + 1);
                    if ($end === false) {
                        $result .= substr($sql, $index + 1);
                        break;
                    }
                    $result .= substr($sql, $index + 1, $end - $index - 1) . $delimiter;
                    $index = $end + strlen($delimiter) - 1;
                    continue;
                }
            }

            if ($character === '?') {
                $result .= $replacement('?') ?? '?';
                continue;
            }
            if ($character === ':' && $next !== ':' && $next !== '=' && ($sql[$index - 1] ?? '') !== ':') {
                $isIdentifierStart = ($next >= 'A' && $next <= 'Z')
                    || ($next >= 'a' && $next <= 'z')
                    || $next === '_';
                if ($isIdentifierStart) {
                    $end = $index + 2;
                    while ($end < $length) {
                        $candidate = $sql[$end];
                        if (!(($candidate >= 'A' && $candidate <= 'Z')
                            || ($candidate >= 'a' && $candidate <= 'z')
                            || ($candidate >= '0' && $candidate <= '9')
                            || $candidate === '_')) {
                            break;
                        }
                        $end++;
                    }
                    $placeholder = substr($sql, $index, $end - $index);
                    $result .= $replacement($placeholder) ?? $placeholder;
                    $index = $end - 1;
                    continue;
                }
            }
            $result .= $character;
        }

        return $result;
    }

    /** @return string|null */
    private function postgresDollarQuoteDelimiter(string $sql, int $offset): ?string
    {
        $remaining = substr($sql, $offset);
        return preg_match('/\A\$(?:[A-Za-z_][A-Za-z0-9_]*)?\$/', $remaining, $matches) === 1
            ? $matches[0]
            : null;
    }

    /**
     * Builds a CTE from trusted SQL while binding only placeholders outside quoted text and comments.
     * The public raw() method and its behavior are unchanged.
     *
     * @param array<int|string, mixed> $params
     */
    private function createCteRawBuilder(string $sql, array $params): self
    {
        $builder = new self();
        $builder->driver = $this->driver;
        $builder->sql = [$sql];

        if ($params === []) {
            return $builder;
        }

        if (array_is_list($params)) {
            $parameterIndex = 0;
            $builder->sql = [$this->replaceSqlPlaceholders(
                $sql,
                static function (string $placeholder) use ($builder, $params, &$parameterIndex): ?string {
                    if ($placeholder !== '?') {
                        return null;
                    }
                    return $builder->bindValue($params[$parameterIndex++] ?? null);
                },
            )];
            return $builder;
        }

        $builder->sql = [$this->replaceSqlPlaceholders(
            $sql,
            static function (string $placeholder) use ($builder, $params): ?string {
                if (!str_starts_with($placeholder, ':')) {
                    return null;
                }
                $name = substr($placeholder, 1);
                $key = array_key_exists($name, $params) ? $name : $placeholder;
                return array_key_exists($key, $params) ? $builder->bindValue($params[$key]) : null;
            },
        )];

        return $builder;
    }
}
