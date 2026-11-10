<?php

declare(strict_types=1);

namespace App\Exceptions\Wallet;

use DomainException;

/** Interrompe a movimentação quando uma carteira necessária não está disponível. */
class WalletNotFound extends DomainException
{
    public function __construct()
    {
        parent::__construct('Carteira indisponível para esta solicitação.');
    }
}
