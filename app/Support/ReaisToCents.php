<?php

declare(strict_types=1);

namespace App\Support;

class ReaisToCents
{
    /** Converte a escrita decimal brasileira sem passar por ponto flutuante. */
    public static function parse(mixed $value): ?int
    {
        if (! is_string($value) || preg_match('/\A(0|[1-9][0-9]*)(?:,([0-9]{1,2}))?\z/D', $value, $matches) !== 1) {
            return null;
        }

        $digits = ltrim($matches[1].str_pad($matches[2] ?? '', 2, '0'), '0');
        if ($digits === '') {
            return null;
        }

        $limit = (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            return null;
        }

        return (int) $digits;
    }
}
