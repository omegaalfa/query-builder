<?php

declare(strict_types=1);

namespace Omegaalfa\QueryBuilder\Internal;

use PDOException;
use Throwable;

/** @internal Carries both failures when a transaction rollback cannot be confirmed. */
final class RollbackFailureContext extends PDOException
{
    public function __construct(
        private readonly Throwable $originalFailure,
        private readonly Throwable $rollbackFailure,
    ) {
        parent::__construct(
            message: sprintf('Rollback failed: %s', $rollbackFailure->getMessage()),
            code: (int) $rollbackFailure->getCode(),
            previous: $originalFailure,
        );
    }

    public function getOriginalFailure(): Throwable
    {
        return $this->originalFailure;
    }

    public function getRollbackFailure(): Throwable
    {
        return $this->rollbackFailure;
    }
}
