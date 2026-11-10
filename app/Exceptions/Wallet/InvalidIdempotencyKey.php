<?php

declare(strict_types=1);

namespace App\Exceptions\Wallet;

use DomainException;

/** Rejeita chaves que não identificam uma solicitação de forma válida e estável. */
class InvalidIdempotencyKey extends DomainException
{
    public function __construct()
    {
        parent::__construct('Informe uma chave de idempotência válida, com até 128 caracteres e sem espaços nas extremidades.');
    }
}
