<?php

declare(strict_types=1);


namespace Omegaalfa\QueryBuilder;

use Omegaalfa\QueryBuilder\enums\JoinType;
use Omegaalfa\QueryBuilder\enums\OrderDirection;
use Omegaalfa\QueryBuilder\enums\SqlOperator;
use Omegaalfa\QueryBuilder\exceptions\QueryException;
use Omegaalfa\QueryBuilder\interfaces\QueryBuilderInterface;
use Omegaalfa\QueryBuilder\traits\HelperQueryOperationsTrait;

class QueryBuilderOperations implements QueryBuilderInterface
{

    use HelperQueryOperationsTrait;

    /**
     * @var array
     */
    protected array $joins = [];

    /**
     * @var array
     */
    protected array $where = [];

    /**
     * @var array
     */
    protected array $orderBy = [];

    /**
     * @var array
     */
    protected array $groupBy = [];

    /**
     * @var array
     */
    protected array $having = [];

    /**
     * @var array|null
     */
    protected ?array $limit = null;

    /**
     * @var array
     */
    protected array $params = [];

    /**
     * @var array
     */
    protected array $sql = [];


    /**
     * @var string|null
     */
    protected string|null $table = null;


    /**
     * @var array Para agrupar condições OR
     */
    protected array $whereGroups = [];


    /**
     * @var bool
     */
    protected bool $isOrGroup = false;

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
     * @param string $table Nome da tabela ou view.
     * @param array $fields Lista de colunas a selecionar (default: ['*']).
     * @return $this
     */
    public function select(string $table, array $fields = ['*']): self
    {
        $this->resetOperationsState();
        $this->table = $this->quoteIdentifier($table);
        $this->sql = ['SELECT', implode(', ', $fields), 'FROM ' . $this->table];
        return $this;
    }

    /**
     * @return void
     */
    protected function resetOperationsState(): void
    {
        $this->sql = [];
        $this->joins = [];
        $this->where = [];
        $this->orderBy = [];
        $this->groupBy = [];
        $this->having = [];
        $this->table = null;
        $this->limit = null;
        $this->params = [];
        $this->whereGroups = [];
        $this->isOrGroup = false;
    }

    /**
     * Inicia uma operação INSERT.
     *
     * @param string $table Nome da tabela de destino.
     * @param array $data Array associativo coluna => valor.
     * @return $this
     */
    public function insert(string $table, array $data): self
    {
        $this->resetOperationsState();
        $this->table = $this->quoteIdentifier($table);
        $fields = array_keys($data);
        $this->sql = [
            'INSERT INTO',
            $this->table,
            '(' . implode(', ', $fields) . ')',
            'VALUES',
            '(' . implode(', ', array_map(static fn($field) => ':' . $field, $fields)) . ')'
        ];

        foreach ($data as $key => $value) {
            $param = ':' . $key;
            $this->params[$param] = $value;
        }

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
    public function insertBatch(string $table, array $data): self
    {
        if (empty($data)) {
            throw new QueryException("Batch insert requires at least one row");
        }

        $this->resetOperationsState();
        $this->table = $this->quoteIdentifier($table);

        // Pega colunas do primeiro registro
        $firstRow = reset($data);
        $fields = array_keys($firstRow);

        // Valida que todos os registros têm as mesmas colunas
        foreach ($data as $index => $row) {
            if (array_keys($row) !== $fields) {
                throw new QueryException("All rows must have the same columns. Error at index {$index}");
            }
        }

        $quotedFields = array_map([$this, 'quoteIdentifier'], $fields);

        // Constrói placeholders
        $valueSets = [];
        foreach ($data as $rowIndex => $row) {
            $placeholders = [];
            foreach ($fields as $field) {
                $param = ":{$field}_{$rowIndex}";
                $placeholders[] = $param;
                $this->params[$param] = $row[$field];
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
     * Inicia uma operação UPDATE.
     *
     * @param string $table Nome da tabela.
     * @param array $data Array associativo coluna => novo valor.
     * @return $this
     */
    public function update(string $table, array $data): self
    {
        $this->resetOperationsState();
        $assignments = [];
        $this->table = $this->quoteIdentifier($table);
        foreach ($data as $key => $value) {
            $param = ':' . $key;
            $assignments[] = sprintf('%s = %s', $this->quoteIdentifier($key), $param);
            $this->params[$param] = $value;
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
        $param = ':param' . count($this->params);
        $this->where[] = sprintf('%s %s %s', $this->quoteIdentifier($column), $operator->value, $param);
        $this->params[$param] = $value;

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
        $param = ':param' . count($this->params);
        $condition = sprintf('%s %s %s', $this->quoteIdentifier($column), $operator->value, $param);
        $this->params[$param] = $value;

        // Marca que estamos em grupo OR
        if (!$this->isOrGroup && !empty($this->where)) {
            $this->isOrGroup = true;
            $this->whereGroups[] = array_pop($this->where);
        }

        $this->whereGroups[] = $condition;

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
    public function whereIn(string $column, array $values): self
    {
        if (empty($values)) {
            throw new QueryException("Values for IN must be a non-empty array.");
        }

        $placeholders = [];
        foreach ($values as $i => $v) {
            $param = ":{$column}_in_{$i}";
            $placeholders[] = $param;
            $this->params[$param] = $v;
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
    public function whereNotIn(string $column, array $values): self
    {
        if (empty($values)) {
            throw new QueryException("Values for NOT IN must be a non-empty array.");
        }

        $placeholders = [];
        foreach ($values as $i => $v) {
            $param = ":{$column}_notin_{$i}";
            $placeholders[] = $param;
            $this->params[$param] = $v;
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
    public function whereBetween(string $column, array $range): self
    {
        if (count($range) !== 2) {
            throw new QueryException("BETWEEN requires an array with 2 values.");
        }
        $param1 = ":{$column}_bt1";
        $param2 = ":{$column}_bt2";

        $this->where[] = sprintf('%s BETWEEN %s AND %s', $this->quoteIdentifier($column), $param1, $param2);
        $this->params[$param1] = $range[0];
        $this->params[$param2] = $range[1];

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
    public function whereNotBetween(string $column, array $range): self
    {
        if (count($range) !== 2) {
            throw new QueryException("NOT BETWEEN requires an array with 2 values.");
        }
        $param1 = ":{$column}_nbt1";
        $param2 = ":{$column}_nbt2";

        $this->where[] = sprintf('%s NOT BETWEEN %s AND %s', $this->quoteIdentifier($column), $param1, $param2);
        $this->params[$param1] = $range[0];
        $this->params[$param2] = $range[1];

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

        return $this;
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
        $param = ':having' . count($this->params);
        $this->having[] = sprintf('%s %s %s', $this->quoteIdentifier($column), $operator->value, $param);
        $this->params[$param] = $value;

        return $this;
    }

    /**
     * Adiciona uma cláusula HAVING em formato bruto.
     *
     * Exemplo: ->havingRaw('COUNT(*) > 5')
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
        $this->limit = [$limit, $offset];

        return $this;
    }

    /**
     * Define uma consulta SQL manual (raw query), opcionalmente com parâmetros.
     *
     * Exemplo: ->raw('SELECT * FROM doenca WHERE status = ?', [1])
     *
     * @param string $query SQL completo.
     * @param array $params Parâmetros para binding.
     * @return $this
     */
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
                $normalizedQuery = preg_replace_callback('/\?/', static function () use (&$paramCounter, $params, &$normalizedParams) {
                    $placeholder = ':raw_param' . $paramCounter;
                    $normalizedParams[$placeholder] = $params[$paramCounter] ?? null;
                    $paramCounter++;
                    return $placeholder;
                }, $query);

                $this->sql = [$normalizedQuery];
                $this->params = $normalizedParams;
            } else {
                // Params já estão nomeados
                $this->params = $params;
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

        if ($this->joins) {
            $query[] = implode(' ', $this->joins);
        }

        // Fecha grupo OR pendente como sub-condição (liga por AND implícito)
        if ($this->isOrGroup && !empty($this->whereGroups)) {
            $this->where[] = '(' . implode(' OR ', $this->whereGroups) . ')';
            $this->whereGroups = [];
            $this->isOrGroup = false;
        }

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

        return implode(' ', $query);
    }
}
