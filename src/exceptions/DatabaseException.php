<?php

declare(strict_types=1);


namespace Omegaalfa\QueryBuilder\exceptions;

use Exception;

class DatabaseException extends Exception
{
	/**
	 * @var Exception|null
	 */
	private ?Exception $previousException;

	/**
	 * Construtor da classe DatabaseException.
	 *
	 * @param  string          $message            Mensagem da exceção.
	 * @param  int             $code               Código da exceção.
	 * @param  Exception|null  $previousException  Exceção anterior encadeada, se houver.
	 */
	public function __construct(string $message, int $code = 0, ?Exception $previousException = null)
	{
		$this->previousException = $previousException;
		parent::__construct($message, $code, $previousException);
	}

	/**
	 * Retorna a exceção anterior, se existir.
	 *
	 * @return Exception|null
	 */
	public function getPreviousException(): ?Exception
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
