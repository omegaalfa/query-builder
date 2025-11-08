<?php

declare(strict_types=1);

namespace Omegaalfa\QueryBuilder\traits;

use Omegaalfa\QueryBuilder\enums\SqlOperator;
use Omegaalfa\QueryBuilder\exceptions\QueryException;

/**
 * Trait HelperQueryOperationsTrait
 *
 * 🔒 Fornece métodos utilitários para validação de operadores SQL
 * e escaping seguro de identificadores (tabelas/colunas/aliases).
 *
 * ⚠️ Importante:
 *   - Nunca use `quoteIdentifier()` para valores (use bindParam/bindValue).
 *   - O escaping segue o padrão MySQL por padrão; adapte se o driver for PostgreSQL.
 */
trait HelperQueryOperationsTrait
{
    /**
     * Lista de operadores suportados para where/orWhere simples
     */
    protected const array SUPPORTED_OPERATORS = [
        SqlOperator::EQUALS,
        SqlOperator::NOT_EQUALS,
        SqlOperator::GREATER_THAN,
        SqlOperator::LESS_THAN,
        SqlOperator::LESS_THAN_OR_EQUALS,
        SqlOperator::GREATER_THAN_OR_EQUALS,
        SqlOperator::LIKE,
        SqlOperator::NOT_LIKE,
    ];

    /**
     * @var string
     */
    protected string $driver = 'mysql';

    /**
     * Define o driver atual, normalizando o nome (ex: MySQL, PostgreSQL, SQLite).
     *
     * @param string $name
     * @return void
     */
    protected function setDriver(string $name): void
    {
        $this->driver = $name;
    }

    /**
     * Normaliza o operador e valida se é suportado
     *
     * @param SqlOperator|string $operator
     * @return SqlOperator
     * @throws QueryException
     */
    protected function normalizeAndValidateOperator(SqlOperator|string $operator): SqlOperator
    {
        if (is_string($operator)) {
            $operator = SqlOperator::tryFrom(strtoupper($operator))
                ?? throw new QueryException("Invalid SQL operator: $operator");
        }

        if (!in_array($operator, self::SUPPORTED_OPERATORS, true)) {
            throw new QueryException("Operator $operator->value not allowed in where/orWhere(). Use specific method.");
        }

        return $operator;
    }

    /**
     * Escapa nomes de tabelas e colunas, suportando alias (ex: "tabela AS t").
     *
     * - "doenca" → `doenca`
     * - "doenca as d" → `doenca` AS `d`
     * - "d.id" → `d`.`id`
     *
     * @param string $identifier
     * @return string
     */
    private function quoteIdentifier(string $identifier): string
    {
        static $cache = [];

        if (isset($cache[$identifier])) {
            return $cache[$identifier];
        }

        $identifier = trim(preg_replace('/\s+/', ' ', $identifier));

        // Determina o caractere de escape conforme o driver
        $quoteChar = match ($this->driver) {
            'pgsql', 'sqlite' => '"',
            default => '`',
        };

        // Trata alias (ex: "tabela AS t")
        if (stripos($identifier, ' as ') !== false) {
            [$name, $alias] = preg_split('/\s+as\s+/i', $identifier);
            return $cache[$identifier] = sprintf(
                '%s AS %s',
                $this->quoteIdentifier($name),
                $this->quoteIdentifier($alias)
            );
        }

        // Divide por ponto (tabela.coluna)
        $parts = explode('.', $identifier);
        $quoted = array_map(static function ($part) use ($quoteChar) {
            return $part === '*'
                ? '*'
                : sprintf('%s%s%s', $quoteChar, str_replace($quoteChar, $quoteChar . $quoteChar, $part), $quoteChar);
        }, $parts);

        return $cache[$identifier] = implode('.', $quoted);

    }
}