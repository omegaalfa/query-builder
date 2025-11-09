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
        $this->limit = null;
        $this->params = [];
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

        $this->sql = ['UPDATE',  $this->table, 'SET', implode(', ', $assignments)];
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

        $this->where[] = empty($this->where)
            ? $condition
            : '(' . array_pop($this->where) . ' OR ' . $condition . ')';

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
     */
    public function join(string $table, string $key, string $operator, string $refer, JoinType $type = JoinType::INNER): self
    {
        // ⚙️ MySQL não suporta FULL JOIN → usar emulação
        if ($type === JoinType::FULL && $this->driver === 'mysql') {
            $this->joins[] = sprintf(
                '(SELECT * FROM %1$s LEFT JOIN %2$s ON %3$s %4$s %5$s
              UNION
              SELECT * FROM %1$s RIGHT JOIN %2$s ON %3$s %4$s %5$s)',
                $this->quoteIdentifier($this->table ?? 't1'),
                $this->quoteIdentifier($table),
                $this->quoteIdentifier($key),
                $operator,
                $this->quoteIdentifier($refer)
            );
            return $this;
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
        $this->params = $params;

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

        if ($this->where) {
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

        if ($this->limit) {
            $query[] = "LIMIT {$this->limit[1]} , {$this->limit[0]}";
        }

        return implode(' ', $query);
    }
}
