<?php

declare(strict_types=1);


namespace Omegaalfa\QueryBuilder\interfaces;


use Omegaalfa\QueryBuilder\PaginationDTO;

interface PaginatorInterface
{
    /**
     * @param int $total
     * @param int $perPage
     * @param int $currentPage
     *
     * @return PaginationDTO
     */
    public function paginate(int $total, int $perPage, int $currentPage): PaginationDTO;
}
