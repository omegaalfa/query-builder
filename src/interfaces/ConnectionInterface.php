<?php

declare(strict_types=1);


namespace Omegaalfa\QueryBuilder\interfaces;

use PDO;

interface ConnectionInterface
{

    /**
     * @return void
     */
    public function connect(): void;

    /**
     * @return PDO
     */
    public function pdo() : PDO;

    /**
     * @return void
     */
    public function disconnect(): void;

    /**
     * @param callable $callback
     *
     * @return mixed
     */
    public function transaction(callable $callback): mixed;

    /**
     * @return string
     */
    public function getDriver(): string;

}
