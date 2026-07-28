<?php

declare(strict_types=1);

namespace Omegaalfa\QueryBuilder\PostgreSQL\PgVector;

use Omegaalfa\QueryBuilder\Exceptions\UnsupportedDatabaseFeatureException;
use Omegaalfa\QueryBuilder\Interfaces\SqlExpressionInterface;
use Omegaalfa\QueryBuilder\SqlCompilationContext;

final readonly class VectorDistance implements SqlExpressionInterface
{
    /**
     * @var Vector
     */
    private Vector $vector;

    /** @param Vector|array<int, int|float> $vector */
    public function __construct(
        private string       $column,
        Vector|array         $vector,
        private VectorMetric $metric = VectorMetric::L2,
        ?int                 $dimensions = null,
    )
    {
        $this->vector = $vector instanceof Vector ? $vector : new Vector($vector, $dimensions);
    }

    /**
     * @param string $column
     * @param Vector|array $vector
     * @param int|null $dimensions
     * @return self
     */
    public static function l2(string $column, Vector|array $vector, ?int $dimensions = null): self
    {
        return new self($column, $vector, VectorMetric::L2, $dimensions);
    }

    /**
     * @param string $column
     * @param Vector|array $vector
     * @param int|null $dimensions
     * @return self
     */
    public static function cosine(string $column, Vector|array $vector, ?int $dimensions = null): self
    {
        return new self($column, $vector, VectorMetric::COSINE, $dimensions);
    }

    /**
     * @param string $column
     * @param Vector|array $vector
     * @param int|null $dimensions
     * @return self
     */
    public static function innerProduct(string $column, Vector|array $vector, ?int $dimensions = null): self
    {
        return new self($column, $vector, VectorMetric::INNER_PRODUCT, $dimensions);
    }

    /**
     * @param string $column
     * @param Vector|array $vector
     * @param int|null $dimensions
     * @return self
     */
    public static function l1(string $column, Vector|array $vector, ?int $dimensions = null): self
    {
        return new self($column, $vector, VectorMetric::L1, $dimensions);
    }

    /**
     * @param SqlCompilationContext $context
     * @return string
     * @throws UnsupportedDatabaseFeatureException
     */
    public function compile(SqlCompilationContext $context): string
    {
        if ($context->driver() !== 'pgsql') {
            throw new UnsupportedDatabaseFeatureException('pgvector expressions require the pgsql PDO driver.');
        }
        return sprintf(
            '%s %s CAST(%s AS vector)',
            $context->quoteIdentifier($this->column),
            $this->metric->value,
            $context->bindObject($this, $this->vector),
        );
    }
}
