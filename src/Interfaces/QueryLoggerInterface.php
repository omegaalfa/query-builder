<?php


declare(strict_types=1);


namespace Omegaalfa\QueryBuilder\Interfaces;

use Throwable;

interface QueryLoggerInterface
{
    /**
     * @param string $sql
     * @param array $params
     * @param float $duration
     * @param int $rowCount
     * @return void
     */
    /** @param array<string, mixed> $params */
    public function logQuery(string $sql, array $params, float $duration, int $rowCount): void;


    /**
     * @param string $sql
     * @param array $params
     * @param Throwable $error
     * @return void
     */
    /** @param array<string, mixed> $params */
    public function logError(string $sql, array $params, Throwable $error): void;
}
