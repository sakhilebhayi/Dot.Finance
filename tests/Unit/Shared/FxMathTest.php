<?php

namespace Tests\Unit\Shared;

use App\Services\Shared\FxMath;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FxMathTest extends TestCase
{
    public function test_convert_between_two_two_digit_currencies(): void
    {
        // USD 100.00 at 18.4649 = ZAR 1,846.49
        $this->assertSame(184_649, FxMath::convert(10_000, 1_846_490_000, 2, 2));
    }

    public function test_convert_honours_each_currencys_minor_units(): void
    {
        // JPY has no minor unit: 1000 yen at 0.1030 ZAR/yen = ZAR 103.00
        $this->assertSame(10_300, FxMath::convert(1_000, 10_300_000, 0, 2));
        // and back: ZAR 103.00 at 9.7087379 yen per rand = 1000 yen
        $this->assertSame(1_000, FxMath::convert(10_300, 970_873_790, 2, 0));
        // KWD has three: 1.000 KWD (1000 fils) at 60.00 ZAR = ZAR 60.00
        $this->assertSame(6_000, FxMath::convert(1_000, 6_000_000_000, 3, 2));
    }

    public function test_convert_rounds_half_away_from_zero(): void
    {
        // 1 cent at 0.5 = 0.5 cent -> 1 ; -1 cent -> -1
        $this->assertSame(1, FxMath::convert(1, 50_000_000, 2, 2));
        $this->assertSame(-1, FxMath::convert(-1, 50_000_000, 2, 2));
        $this->assertSame(0, FxMath::convert(1, 49_999_999, 2, 2));
    }

    public function test_a_large_amount_converts_exactly(): void
    {
        // ZAR 900 billion in cents at a rate near 18.5 would overflow a plain product
        $this->assertSame(1_665_000_000_000_000, FxMath::convert(90_000_000_000_000, 1_850_000_000, 2, 2));
    }

    public function test_invert(): void
    {
        $this->assertSame(50_000_000, FxMath::invert(200_000_000));
        $this->assertSame(33_333_333, FxMath::invert(300_000_000));
        $this->assertSame(100_000_000, FxMath::invert(100_000_000));
    }

    #[DataProvider('rates')]
    public function test_parse_and_format_a_rate(string $text, int $e8, string $formatted): void
    {
        $this->assertSame($e8, FxMath::parseRate($text));
        $this->assertSame($formatted, FxMath::formatRate($e8));
    }

    public static function rates(): array
    {
        return [
            ['18.4649', 1_846_490_000, '18.4649'],
            ['1', 100_000_000, '1'],
            ['0.00000001', 1, '0.00000001'],
            ['20208.93', 2_020_893_000_000, '20208.93'],
            ['0.83415', 83_415_000, '0.83415'],
        ];
    }

    #[DataProvider('badRates')]
    public function test_a_bad_rate_is_refused(string $text): void
    {
        $this->expectException(InvalidArgumentException::class);
        FxMath::parseRate($text);
    }

    public static function badRates(): array
    {
        return [['0'], ['0.0'], ['abc'], ['1.123456789'], ['-1'], ['1e3'], [''], ['1,5']];
    }

    public function test_convert_refuses_a_non_positive_rate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FxMath::convert(100, 0, 2, 2);
    }

    public function test_realised_difference_on_a_receivable(): void
    {
        // USD 1,000.00 owed to us, booked at 18.00, settled at 18.50: a gain of ZAR 500.00
        $this->assertSame(50_000, FxMath::realised(100_000, 1_800_000_000, 1_850_000_000, 2, 2, 'asset'));
        // settled at 17.50: a loss of ZAR 500.00
        $this->assertSame(-50_000, FxMath::realised(100_000, 1_800_000_000, 1_750_000_000, 2, 2, 'asset'));
    }

    public function test_realised_difference_on_a_payable_is_the_mirror(): void
    {
        $this->assertSame(-50_000, FxMath::realised(100_000, 1_800_000_000, 1_850_000_000, 2, 2, 'liability'));
        $this->assertSame(50_000, FxMath::realised(100_000, 1_800_000_000, 1_750_000_000, 2, 2, 'liability'));
    }

    public function test_no_rate_movement_is_no_difference(): void
    {
        $this->assertSame(0, FxMath::realised(100_000, 1_800_000_000, 1_800_000_000, 2, 2, 'asset'));
    }

    public function test_unrealised_difference_at_the_closing_rate(): void
    {
        // an open USD 1,000.00 receivable carried at ZAR 18,000.00, closing rate 18.25: a gain of ZAR 250.00
        $this->assertSame(25_000, FxMath::unrealised(100_000, 1_800_000, 1_825_000_000, 2, 2, 'asset'));
        $this->assertSame(-25_000, FxMath::unrealised(100_000, 1_800_000, 1_825_000_000, 2, 2, 'liability'));
    }

    public function test_the_kind_must_be_named(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FxMath::realised(1, 1, 2, 2, 2, 'equity');
    }

    public function test_the_realised_difference_is_consistent_with_the_conversions(): void
    {
        mt_srand(20261109);
        for ($i = 0; $i < 200; $i++) {
            $amount = mt_rand(1, 50_000_000);
            $book = mt_rand(100_000_000, 3_000_000_000);
            $settle = mt_rand(100_000_000, 3_000_000_000);

            $gain = FxMath::realised($amount, $book, $settle, 2, 2, 'asset');
            $this->assertSame(FxMath::convert($amount, $settle, 2, 2) - FxMath::convert($amount, $book, 2, 2), $gain);
            $this->assertSame(-$gain, FxMath::realised($amount, $book, $settle, 2, 2, 'liability'));
        }
    }
}
