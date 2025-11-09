<?php

namespace Omegaalfa\QueryBuilder\exceptions;

use RuntimeException;
use Throwable;

class CacheException extends RuntimeException
{
    /**
     * @param string $message
     * @param string $key
     * @param Throwable|null $previous
     */
    public function __construct(
        string                 $message,
        public readonly string $key = '',
        ?Throwable             $previous = null
    )
    {
        parent::__construct($message, 0, $previous);
    }
}