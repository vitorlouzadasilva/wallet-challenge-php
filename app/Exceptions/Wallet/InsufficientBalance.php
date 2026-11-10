<?php

declare(strict_types=1);

namespace App\Exceptions\Wallet;

use DomainException;

/** Interrompe a transferência quando o saldo bloqueado não cobre o débito. */
class InsufficientBalance extends DomainException
{
    public function __construct()
    {
        parent::__construct('Saldo insuficiente para realizar a transferência.');
    }
}
