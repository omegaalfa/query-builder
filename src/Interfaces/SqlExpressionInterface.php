<?php

declare(strict_types=1);

namespace Omegaalfa\QueryBuilder\Interfaces;

use Omegaalfa\QueryBuilder\SqlCompilationContext;

/** A trusted, library-authored expression. This is not a raw SQL API. */
interface SqlExpressionInterface
{
    /**
     * @param SqlCompilationContext $context
     * @return string
     */
    public function compile(SqlCompilationContext $context): string;
}
