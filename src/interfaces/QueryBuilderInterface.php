<?php

declare(strict_types=1);

namespace Omegaalfa\QueryBuilder\interfaces;

use Omegaalfa\QueryBuilder\enums\JoinType;
use Omegaalfa\QueryBuilder\enums\OrderDirection;
use Omegaalfa\QueryBuilder\enums\SqlOperator;

/**
 * Interface base para o construtor de consultas SQL fluente.
 *
 * Fornece uma API fluente para montar consultas SQL dinâmicas (SELECT, INSERT, UPDATE, DELETE),
 * com suporte a filtros, ordenação, agrupamento, junções e cláusulas complexas.
 */
interface QueryBuilderInterface
{
    /**
     * Define um alias para a tabela principal da consulta.
     *
     * Exemplo: ->select('doenca')->alias('d')
     *
     * @param string $alias Alias a ser aplicado à tabela.
     * @return $this
     */
    public function alias(string $alias): self;

    /**
     * Inicia uma consulta SELECT.
     *
     * @param string $table Nome da tabela ou view.
     * @param array $fields Lista de colunas a selecionar (default: ['*']).
     * @return $this
     */
    public function select(string $table, array $fields = ['*']): self;

    /**
     * Inicia uma operação INSERT.
     *
     * @param string $table Nome da tabela de destino.
     * @param array $data Array associativo coluna => valor.
     * @return $this
     */
    public function insert(string $table, array $data): self;

    /**
     * Inicia uma operação UPDATE.
     *
     * @param string $table Nome da tabela.
     * @param array $data Array associativo coluna => novo valor.
     * @return $this
     */
    public function update(string $table, array $data): self;

    /**
     * Inicia uma operação DELETE.
     *
     * @param string $table Nome da tabela.
     * @return $this
     */
    public function delete(string $table): self;

    /**
     * Adiciona uma condição WHERE.
     *
     * Exemplo: ->where('status', SqlOperator::EQUALS, 1)
     *
     * @param string $column Coluna a comparar.
     * @param SqlOperator $operator Operador lógico (EQUALS, LIKE, etc).
     * @param mixed $value Valor de comparação.
     * @return $this
     */
    public function where(string $column, SqlOperator $operator, mixed $value): self;

    /**
     * Adiciona uma condição WHERE ... IN (...).
     *
     * Exemplo: ->whereIn('status', [1, 2, 3])
     *
     * @param string $column Coluna alvo.
     * @param array $values Lista de valores.
     * @return $this
     */
    public function whereIn(string $column, array $values): self;

    /**
     * Adiciona uma condição WHERE ... NOT IN (...).
     *
     * @param string $column Coluna alvo.
     * @param array $values Lista de valores.
     * @return $this
     */
    public function whereNotIn(string $column, array $values): self;

    /**
     * Adiciona uma condição WHERE ... BETWEEN ... AND ...
     *
     * @param string $column Coluna alvo.
     * @param array $range Array com dois valores [min, max].
     * @return $this
     */
    public function whereBetween(string $column, array $range): self;

    /**
     * Adiciona uma condição WHERE ... NOT BETWEEN ... AND ...
     *
     * @param string $column Coluna alvo.
     * @param array $range Array com dois valores [min, max].
     * @return $this
     */
    public function whereNotBetween(string $column, array $range): self;

    /**
     * Adiciona uma condição WHERE ... IS NULL.
     *
     * @param string $column Coluna alvo.
     * @return $this
     */
    public function whereNull(string $column): self;

    /**
     * Adiciona uma condição WHERE ... IS NOT NULL.
     *
     * @param string $column Coluna alvo.
     * @return $this
     */
    public function whereNotNull(string $column): self;

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
    public function join(string $table, string $key, string $operator, string $refer, JoinType $type = JoinType::INNER): self;

    /**
     * Define a ordenação da consulta.
     *
     * Exemplo: ->orderBy('idDoenca', OrderDirection::DESC)
     *
     * @param string $column Coluna de ordenação.
     * @param OrderDirection $direction Direção (ASC ou DESC).
     * @return $this
     */
    public function orderBy(string $column, OrderDirection $direction = OrderDirection::ASC): self;

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
     */
    public function having(string $column, SqlOperator $operator, mixed $value): self;

    /**
     * Adiciona uma cláusula HAVING em formato bruto.
     *
     * Exemplo: ->havingRaw('COUNT(*) > 5')
     *
     * @param string $condition Condição completa em SQL.
     * @return $this
     */
    public function havingRaw(string $condition): self;

    /**
     * Define o limite de resultados e, opcionalmente, o deslocamento.
     *
     * Exemplo: ->limit(10, 5)
     *
     * @param int $limit Quantidade máxima de registros.
     * @param int $offset Posição inicial do cursor (default: 0).
     * @return $this
     */
    public function limit(int $limit, int $offset = 0): self;

    /**
     * Define uma coluna ou expressão para agrupamento (GROUP BY).
     *
     * @param string $column Coluna ou expressão a agrupar.
     * @return $this
     */
    public function groupBy(string $column): self;

    /**
     * Define uma consulta SQL manual (raw query), opcionalmente com parâmetros.
     *
     * Exemplo: ->raw('SELECT * FROM doenca WHERE status = ?', [1])
     *
     * @param string $query SQL completo.
     * @param array $params Parâmetros para binding.
     * @return $this
     */
    public function raw(string $query, array $params = []): self;

    /**
     * Adiciona uma condição OR WHERE.
     *
     * Exemplo: ->orWhere('nome', 'LIKE', '%botulismo%')
     *
     * @param string $column Coluna de comparação.
     * @param string $operator Operador (ex: '=', 'LIKE', etc).
     * @param mixed $value Valor de comparação.
     * @return $this
     */
    public function orWhere(string $column, string $operator, mixed $value): self;

    /**
     * Retorna a string SQL final construída pelo QueryBuilder.
     *
     * Exemplo de retorno:
     *   SELECT * FROM doenca WHERE status = ? ORDER BY nome ASC LIMIT 10
     *
     * @return string Consulta SQL gerada.
     */
    public function getQuerySql(): string;
}
