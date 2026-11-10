<?php

declare(strict_types=1);

namespace App\Exceptions\Wallet;

use DomainException;

/** Rejeita coerções e valores não positivos na fronteira monetária do serviço. */
class InvalidAmount extends DomainException
{
    public function __construct()
    {
        parent::__construct('O valor deve ser um inteiro positivo em centavos.');
    }
}
