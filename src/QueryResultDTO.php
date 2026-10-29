<?php

declare(strict_types=1);


namespace Omegaalfa\QueryBuilder;

readonly class QueryResultDTO
{
    /**
     * @param iterable<mixed> $data
     * @param int $count
     * @param PaginationDTO|CursorPaginationDTO|null $pagination
     * @param false|string $insertId Present only when returnLastInsertId() was requested.
     */
    public function __construct(
        public iterable       $data,
        public int            $count,
        public PaginationDTO|CursorPaginationDTO|null $pagination = null,
        public false|string   $insertId = false
    ){}
}
