<?php

declare(strict_types=1);


namespace Omegaalfa\QueryBuilder;

final readonly class PaginationDTO
{
	/**
	 * @param  int  $currentPage
	 * @param  int  $perPage
	 * @param  int  $totalPages
	 * @param  int  $totalItems
	 */
	public function __construct(
		public int $currentPage,
		public int $perPage,
		public int $totalPages,
		public int $totalItems
	) {}
}
