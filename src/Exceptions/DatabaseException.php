<?php

declare(strict_types=1);


namespace Omegaalfa\QueryBuilder\Exceptions;

use Exception;
use Throwable;

class DatabaseException extends Exception
{
	/**
	 * @var Throwable|null
	 */
	private ?Throwable $previousException;

	/**
	 * Construtor da classe DatabaseException.
	 *
	 * @param  string          $message            Mensagem da exceção.
	 * @param  int             $code               Código da exceção.
	 * @param  Throwable|null  $previousException  Exceção anterior encadeada, se houver.
	 */
	public function __construct(string $message, int $code = 0, ?Throwable $previousException = null)
	{
		$this->previousException = $previousException;
		parent::__construct($message, $code, $previousException);
	}

	/**
	 * Retorna a exceção anterior, se existir.
	 *
	 * @return Throwable|null
	 */
	public function getPreviousException(): ?Throwable
	{
		return $this->previousException;
	}

	/**
	 * Retorna a mensagem completa da exceção, incluindo detalhes encadeados.
	 *
	 * @return string
	 */
	public function getDetailedMessage(): string
	{
		$message = $this->getMessage();
		if ($this->previousException) {
			$message .= " | Previous: {$this->previousException->getMessage()}";
		}
		return $message;
	}
}
