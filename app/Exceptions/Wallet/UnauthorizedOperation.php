<?php

declare(strict_types=1);

namespace App\Exceptions\Wallet;

use DomainException;

/** Rejeita falta de autorização sem distinguir operação alheia de inexistente. */
class UnauthorizedOperation extends DomainException
{
    public function __construct()
    {
        parent::__construct('Solicitação financeira não autorizada.');
    }
}
