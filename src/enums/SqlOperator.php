<?php

declare(strict_types=1);


namespace Omegaalfa\QueryBuilder\enums;

enum SqlOperator: string
{
    // Condicionais
    case EQUALS = '=';
    case NOT_EQUALS = '!=';
    case GREATER_THAN = '>';
    case LESS_THAN = '<';
    case GREATER_THAN_OR_EQUALS = '>=';
    case LESS_THAN_OR_EQUALS = '<=';
    case LIKE = 'LIKE';
    case NOT_LIKE = 'NOT LIKE';
    case IN = 'IN';
    case NOT_IN = 'NOT IN';
    case IS = 'IS';
    case IS_NOT = 'IS NOT';
    case BETWEEN = 'BETWEEN';
    case NOT_BETWEEN = 'NOT BETWEEN';

    // Joins
    case INNER_JOIN = 'INNER JOIN';
    case LEFT_JOIN = 'LEFT JOIN';
    case RIGHT_JOIN = 'RIGHT JOIN';

    // Ordenação
    case ASC = 'ASC';
    case DESC = 'DESC';
}