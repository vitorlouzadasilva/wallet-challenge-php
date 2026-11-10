<?php

namespace Tests\Unit;

use App\Support\BrazilianCurrency;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BrazilianCurrencyTest extends TestCase
{
    #[DataProvider('amounts')]
    public function test_formats_signed_cents_without_losing_precision(int $cents, string $expected): void
    {
        self::assertSame($expected, BrazilianCurrency::format($cents));
    }

    public static function amounts(): array
    {
        return [
            [0, 'R$ 0,00'],
            [1, 'R$ 0,01'],
            [10, 'R$ 0,10'],
            [99, 'R$ 0,99'],
            [100, 'R$ 1,00'],
            [123456, 'R$ 1.234,56'],
            [-1, '-R$ 0,01'],
            [-123456, '-R$ 1.234,56'],
            [PHP_INT_MAX, 'R$ 92.233.720.368.547.758,07'],
            [PHP_INT_MIN, '-R$ 92.233.720.368.547.758,08'],
        ];
    }
}
