<?php

declare(strict_types=1);

namespace Omegaalfa\QueryBuilder;

use Closure;
use Omegaalfa\QueryBuilder\Exceptions\UnsupportedDatabaseFeatureException;
use Omegaalfa\QueryBuilder\Interfaces\DatabaseValueInterface;

final class SqlCompilationContext
{

    /** @param array<string, mixed> $params */
    public function __construct(
        private array             &$params,
        private readonly string   $driver,
        private readonly Closure $identifierQuoter,
    )
    {
    }

    /**
     * @return string
     */
    public function driver(): string
    {
        return $this->driver;
    }

    /**
     * @param string $identifier
     * @return string
     */
    public function quoteIdentifier(string $identifier): string
    {
        return ($this->identifierQuoter)($identifier);
    }

    /**
     * @param object $owner
     * @param mixed $value
     * @return string
     * @throws UnsupportedDatabaseFeatureException
     */
    public function bindObject(object $owner, mixed $value): string
    {
        // Native PDO requires one named marker per physical occurrence.
        return $this->bind($value);
    }

    /**
     * @param mixed $value
     * @return string
     * @throws UnsupportedDatabaseFeatureException
     */
    public function bind(mixed $value): string
    {
        if ($value instanceof DatabaseValueInterface && !$value->supportsDriver($this->driver)) {
            throw new UnsupportedDatabaseFeatureException(
                sprintf('%s is not supported by the %s driver.', $value::class, $this->driver)
            );
        }

        $placeholder = ':expr' . count($this->params);
        $this->params[$placeholder] = $value;
        return $placeholder;
    }
}
