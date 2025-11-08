<?php

declare(strict_types=1);


namespace Omegaalfa\QueryBuilder\exceptions;

use Exception;

class QueryException extends Exception
{
	/**
	 * @var string|null
	 */
	private ?string $sql;

	/**
	 * @var array
	 */
	private array $bindings;

	/**
	 * Construtor da classe QueryException.
	 *
	 * @param  string          $message            Mensagem da exceção.
	 * @param  string|null     $sql                Consulta SQL que causou o erro.
	 * @param  array           $bindings           Parâmetros usados na consulta.
	 * @param  int             $code               Código da exceção.
	 * @param  Exception|null  $previousException  Exceção anterior encadeada, se houver.
	 */
	public function __construct(
		string $message,
		?string $sql = null,
		array $bindings = [],
		int $code = 0,
		?Exception $previousException = null
	) {
		$this->sql = $sql;
		$this->bindings = $bindings;
		parent::__construct($message, $code, $previousException);
	}

	/**
	 * Retorna a consulta SQL que causou o erro.
	 *
	 * @return string|null
	 */
	public function getSql(): ?string
	{
		return $this->sql;
	}

	/**
	 * Retorna os parâmetros usados na consulta.
	 *
	 * @return array
	 */
	public function getBindings(): array
	{
		return $this->bindings;
	}

	/**
	 * Retorna uma mensagem detalhada da exceção, incluindo SQL e parâmetros.
	 *
	 * @return string
	 */
	public function getDetailedMessage(): string
	{
		$message = $this->getMessage();
		if ($this->sql) {
			$message .= " | SQL: {$this->sql}";
		}
		if (!empty($this->bindings)) {
			$bindings = implode(', ', array_map(fn($b) => var_export($b, true), $this->bindings));
			$message .= " | Bindings: [{$bindings}]";
		}
		return $message;
	}
}
