<?php

declare(strict_types=1);

namespace App\Exceptions\Wallet;

use DomainException;

/** Impede uma segunda compensação financeira da mesma operação original. */
class OperationAlreadyReversed extends DomainException
{
    public function __construct()
    {
        parent::__construct('A operação já foi revertida.');
    }
}
