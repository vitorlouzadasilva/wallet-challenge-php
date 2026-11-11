<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PasswordByteLimit implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // O bcrypt rejeita NUL ao criar o hash e pode ignorar o sufixo na verificação.
        if (is_string($value) && str_contains($value, "\0")) {
            $fail('A senha contém um caractere inválido.');
        }

        // O bcrypt limita a senha a 72 bytes; rejeitar o excesso evita truncamento silencioso.
        if (is_string($value) && strlen($value) > 72) {
            $fail('A senha é muito longa. Reduza seu tamanho.');
        }
    }
}
