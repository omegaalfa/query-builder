<?php

declare(strict_types=1);

namespace Omegaalfa\QueryBuilder\PostgreSQL\PgVector;

enum VectorMetric: string
{
    case L2 = '<->';
    case INNER_PRODUCT = '<#>';
    case COSINE = '<=>';
    case L1 = '<+>';
}
