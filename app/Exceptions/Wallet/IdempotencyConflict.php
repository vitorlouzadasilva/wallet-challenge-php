<?php

declare(strict_types=1);

namespace App\Exceptions\Wallet;

use DomainException;

/** Sinaliza colisão de ator ou conteúdo sem revelar a operação associada à chave. */
class IdempotencyConflict extends DomainException
{
    public function __construct()
    {
        parent::__construct('A chave de idempotência não pode ser utilizada nesta solicitação.');
    }
}
