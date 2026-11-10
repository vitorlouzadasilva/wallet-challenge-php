<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ReaisToCents;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReaisToCentsTest extends TestCase
{
    #[DataProvider('validValues')]
    public function test_converts_brazilian_decimal_strings_exactly(string $input, int $expected): void
    {
        self::assertSame($expected, ReaisToCents::parse($input));
    }

    public static function validValues(): array
    {
        return [
            ['10', 1000], ['10,5', 1050], ['10,50', 1050], ['0,01', 1],
            ['92233720368547758,07', PHP_INT_MAX],
        ];
    }

    #[DataProvider('invalidValues')]
    public function test_rejects_invalid_or_out_of_range_values(mixed $input): void
    {
        self::assertNull(ReaisToCents::parse($input));
    }

    public static function invalidValues(): array
    {
        return [
            [null], [10], [10.5], [''], ['0'], ['0,00'], ['-1'], ['10,001'],
            ['1.000,00'], ['1,000.00'], ['1e3'], ['10.50'], [' 10,50'],
            ['10,50 '], ['01,00'], ['R$ 10,50'], ['92233720368547758,08'],
            ['999999999999999999999999999999'],
        ];
    }
}
