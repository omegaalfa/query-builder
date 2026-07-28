<?php

declare(strict_types=1);

namespace Omegaalfa\QueryBuilder\PostgreSQL\PgVector;

use InvalidArgumentException;
use JsonException;
use Omegaalfa\QueryBuilder\Interfaces\DatabaseValueInterface;
use PDO;

final readonly class Vector implements DatabaseValueInterface
{
    /** @var list<int|float> */
    private array $values;

    /** @param array<int, int|float> $values */
    public function __construct(array $values, ?int $dimensions = null)
    {
        if ($values === [] || !array_is_list($values)) {
            throw new InvalidArgumentException('A vector must be a non-empty list.');
        }
        if ($dimensions !== null && $dimensions <= 0) {
            throw new InvalidArgumentException('Expected dimensions must be greater than zero.');
        }
        if ($dimensions !== null && count($values) !== $dimensions) {
            throw new InvalidArgumentException(sprintf('Expected %d dimensions, got %d.', $dimensions, count($values)));
        }
        foreach ($values as $value) {
            if (!is_int($value) && !is_float($value)) {
                throw new InvalidArgumentException('Vector elements must be integers or floats.');
            }
            if (is_float($value) && !is_finite($value)) {
                throw new InvalidArgumentException('Vector elements must be finite.');
            }
        }
        $this->values = $values;
    }

    /**
     * @return int
     */
    public function dimensions(): int
    {
        return count($this->values);
    }

    /** @return list<int|float> */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * @return string
     */
    public function toPostgres(): string
    {
        return '[' . implode(',', array_map(self::formatNumber(...), $this->values)) . ']';
    }

    /**
     * @return string
     */
    public function value(): string
    {
        return $this->toPostgres();
    }

    /**
     * @return int
     */
    public function pdoType(): int
    {
        return PDO::PARAM_STR;
    }

    /**
     * @return string|null
     */
    public function sqlType(): ?string
    {
        return 'vector';
    }

    /**
     * @param string $driver
     * @return bool
     */
    public function supportsDriver(string $driver): bool
    {
        return $driver === 'pgsql';
    }

    /**
     * @return string
     */
    public function cacheValue(): string
    {
        return hash('sha256', $this->toPostgres());
    }

    /**
     * @param int|float $value
     * @return string
     * @throws JsonException
     */
    private static function formatNumber(int|float $value): string
    {
        return is_int($value)
            ? (string)$value
            : json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
