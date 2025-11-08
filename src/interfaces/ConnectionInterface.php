<?php

declare(strict_types=1);


namespace Omegaalfa\QueryBuilder\interfaces;

use PDO;

interface ConnectionInterface
{

    /**
     * @return PDO
     */
    public function connect(): PDO;

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
}
