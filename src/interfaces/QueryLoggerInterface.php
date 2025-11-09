<?php


declare(strict_types=1);


namespace Omegaalfa\QueryBuilder\interfaces;

interface QueryLoggerInterface
{
    /**
     * @param string $sql
     * @param array $params
     * @param float $duration
     * @param int $rowCount
     * @return void
     */
    public function logQuery(string $sql, array $params, float $duration, int $rowCount): void;


    /**
     * @param string $sql
     * @param array $params
     * @param \Throwable $error
     * @return void
     */
    public function logError(string $sql, array $params, \Throwable $error): void;
}