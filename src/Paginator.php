<?php

declare(strict_types=1);


namespace Omegaalfa\QueryBuilder;


use Omegaalfa\QueryBuilder\interfaces\PaginatorInterface;

final class Paginator implements PaginatorInterface
{

	/**
	 * @param  int  $total
	 * @param  int  $perPage
	 * @param  int  $currentPage
	 *
	 * @return PaginationDTO
	 */
	public function paginate(int $total, int $perPage, int $currentPage): PaginationDTO
	{
		$totalPages = (int)ceil($total / $perPage);

		return new PaginationDTO(
			currentPage: $currentPage,
			perPage: $perPage,
			totalPages: $totalPages,
			totalItems: $total
		);
	}
}
