<?php

declare(strict_types=1);

namespace Omegaalfa\QueryBuilder;

final readonly class CursorPaginationDTO
{
    public function __construct(
        public int $perPage,
        public ?string $nextCursor,
        public bool $hasMore,
    ) {}
}
