<?php

declare(strict_types=1);


namespace Omegaalfa\QueryBuilder;

readonly class QueryResultDTO
{
    /**
     * @param iterable $data
     * @param int $count
     * @param PaginationDTO|null $pagination
     */
    public function __construct(
        public iterable       $data,
        public int            $count,
        public ?PaginationDTO $pagination = null
    ){}
}
