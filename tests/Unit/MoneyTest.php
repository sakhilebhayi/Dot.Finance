<?php

namespace Tests\Unit;

use App\Rules\MoneyAmount;
use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    #[DataProvider('validAmounts')]
    public function test_parse_turns_decimal_strings_into_exact_cents(string $input, int $expected): void
    {
        $this->assertSame($expected, Money::parse($input));
    }

    public static function validAmounts(): array
    {
        return [
            'whole' => ['12', 1200],
            'one decimal' => ['12.3', 1230],
            'two decimals' => ['12.34', 1234],
            'sub-cent boundary' => ['0.05', 5],
            'negative' => ['-5.5', -550],
            'zero' => ['0', 0],
            'the classic float trap' => ['0.29', 29],
            'large' => ['999999999999.99', 99_999_999_999_999],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_parse_rejects_anything_that_is_not_a_plain_two_decimal_amount(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::parse($input);
    }

    public static function invalidAmounts(): array
    {
        return [
            'three decimals' => ['12.345'],
            'exponent' => ['1e3'],
            'hex' => ['0x10'],
            'grouping comma' => ['1,234.50'],
            'spaces' => [' 12.00'],
            'empty' => [''],
            'letters' => ['abc'],
            'bare dot' => ['.5'],
            'too many digits' => ['1234567890123.00'],
        ];
    }

    public function test_adding_amounts_that_break_floats_stays_exact(): void
    {
        // 0.1 + 0.2 != 0.3 in floating point; in cents it is exactly 30.
        $this->assertSame(30, Money::parse('0.10') + Money::parse('0.20'));
        $this->assertSame('0.30', Money::toDecimalString(Money::parse('0.10') + Money::parse('0.20')));
    }

    public function test_to_decimal_string_round_trips_including_negatives(): void
    {
        $this->assertSame('1234.56', Money::toDecimalString(123456));
        $this->assertSame('-0.05', Money::toDecimalString(-5));
        $this->assertSame('0.00', Money::toDecimalString(0));
    }

    public function test_format_names_the_currency_and_never_uses_a_dollar_sign(): void
    {
        $this->assertSame('ZAR 1,234.56', Money::format(123456, 'ZAR'));
        $this->assertSame('-USD 0.05', Money::format(-5, 'USD'));
        $this->assertSame('+EUR 10.00', Money::format(1000, 'EUR', true));
        $this->assertStringNotContainsString('$', Money::format(123456, 'ZAR'));
    }

    public function test_from_decimal_accepts_legacy_values_without_drifting(): void
    {
        $this->assertSame(4550, Money::fromDecimal(45.50));
        $this->assertSame(4555, Money::fromDecimal(45.55));
        $this->assertSame(123456, Money::fromDecimal('1234.56'));
        $this->assertSame(10000, Money::fromDecimal(100));
        $this->assertSame(0, Money::fromDecimal(null));
        // A float that is not exactly representable still lands on the right cent.
        $this->assertSame(1999, Money::fromDecimal(19.99));
    }

    public function test_money_amount_rule_rejects_floats_exponents_and_non_positive_values(): void
    {
        $rule = new MoneyAmount;
        $messages = [];
        $fail = function (string $m) use (&$messages) {
            $messages[] = $m;
        };

        foreach (['1e3', '12.345', '0', '-5', 'abc'] as $bad) {
            $messages = [];
            $rule->validate('amount', $bad, $fail);
            $this->assertNotEmpty($messages, "{$bad} should be rejected");
        }

        foreach (['12', '12.5', '0.01'] as $good) {
            $messages = [];
            $rule->validate('amount', $good, $fail);
            $this->assertEmpty($messages, "{$good} should be accepted");
        }
    }
}
