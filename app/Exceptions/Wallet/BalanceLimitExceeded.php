<?php

declare(strict_types=1);

namespace App\Exceptions\Wallet;

use DomainException;

/** Impede que crédito ou débito ultrapasse o intervalo monetário do BIGINT assinado. */
class BalanceLimitExceeded extends DomainException
{
    public function __construct()
    {
        parent::__construct('O saldo resultante está fora dos limites permitidos.');
    }
}
