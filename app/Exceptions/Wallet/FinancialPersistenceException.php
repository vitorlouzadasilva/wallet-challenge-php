<?php

declare(strict_types=1);

namespace App\Exceptions\Wallet;

use RuntimeException;
use Throwable;

/** Sinaliza falha de persistência sem retornar sucesso nem expor SQL na mensagem pública. */
class FinancialPersistenceException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('Não foi possível concluir a operação financeira.', 0, $previous);
    }
}
