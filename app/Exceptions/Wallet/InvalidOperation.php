<?php

declare(strict_types=1);

namespace App\Exceptions\Wallet;

use DomainException;

/** Rejeita transferência para a mesma carteira e reversão de outra reversão. */
class InvalidOperation extends DomainException
{
    public function __construct()
    {
        parent::__construct('A operação solicitada não é permitida.');
    }
}
