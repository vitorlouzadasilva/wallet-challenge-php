<?php

namespace App\Support;

class BrazilianCurrency
{
    public static function format(int $cents): string
    {
        // Trabalhar com texto também preserva PHP_INT_MIN, sem abs() nem ponto flutuante.
        $negative = $cents < 0;
        $digits = str_pad(ltrim((string) $cents, '-'), 3, '0', STR_PAD_LEFT);
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', substr($digits, 0, -2));

        return ($negative ? '-' : '').'R$ '.$whole.','.substr($digits, -2);
    }
}
