<?php

declare(strict_types=1);

namespace Omegaalfa\QueryBuilder\Interfaces;

interface DatabaseValueInterface
{
    /**
     * @return mixed
     */
    public function value(): mixed;

    /**
     * @return int
     */
    public function pdoType(): int;

    /**
     * @return string|null
     */
    public function sqlType(): ?string;

    /**
     * @param string $driver
     * @return bool
     */
    public function supportsDriver(string $driver): bool;

    /**
     * @return string
     */
    public function cacheValue(): string;
}
