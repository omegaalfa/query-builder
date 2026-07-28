<?php

declare(strict_types=1);

namespace Omegaalfa\QueryBuilder\Expressions;

use Omegaalfa\QueryBuilder\Interfaces\SqlExpressionInterface;
use Omegaalfa\QueryBuilder\SqlCompilationContext;

final readonly class AliasedExpression implements SqlExpressionInterface
{
    /**
     * @param SqlExpressionInterface $expression
     * @param string $alias
     */
    public function __construct(
        private SqlExpressionInterface $expression,
        private string                 $alias,
    )
    {
    }

    /**
     * @param SqlCompilationContext $context
     * @return string
     */
    public function compile(SqlCompilationContext $context): string
    {
        return sprintf('%s AS %s', $this->expression->compile($context), $context->quoteIdentifier($this->alias));
    }
}
