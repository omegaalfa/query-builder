<?php

declare(strict_types=1);

namespace Omegaalfa\QueryBuilder;

use Closure;
use Omegaalfa\QueryBuilder\Exceptions\UnsupportedDatabaseFeatureException;
use Omegaalfa\QueryBuilder\Interfaces\DatabaseValueInterface;

final class SqlCompilationContext
{
    /** @var array<string, mixed> */
    private array $params;

    private int $counter = 0;

    /** @param array<string, mixed> $params */
    public function __construct(
        array                  &$params,
        private readonly string   $driver,
        private readonly Closure $identifierQuoter,
    )
    {
        $this->params =& $params;
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

        $placeholder = ':param' . $this->counter++;
        $params = $this->params;
        $params[$placeholder] = $value;
        $this->params = $params;
        return $placeholder;
    }
}
