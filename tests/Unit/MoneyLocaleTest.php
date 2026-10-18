<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyLocaleTest extends TestCase
{
    #[DataProvider('parsed')]
    public function test_parse_localized_reads_bank_style_amounts_without_floats(string $raw, string $locale, int $expected): void
    {
        $this->assertSame($expected, Money::parseLocalized($raw, $locale));
    }

    public static function parsed(): array
    {
        return [
            'en grouped' => ['1,234.56', 'en', 123_456],
            'en plain' => ['1234.56', 'en', 123_456],
            'en whole' => ['1,234', 'en', 123_400],
            'en one decimal' => ['12.5', 'en', 1_250],
            'en negative' => ['-1,234.56', 'en', -123_456],
            'en parentheses are negative' => ['(45.50)', 'en', -4_550],
            'en currency prefix' => ['R 1,234.56', 'en', 123_456],
            'en currency suffix' => ['1,234.56 ZAR', 'en', 123_456],
            'en dollar sign' => ['$12.00', 'en', 1_200],
            'en explicit plus' => ['+12.00', 'en', 1_200],
            'eu space grouped' => ['1 234,56', 'eu', 123_456],
            'eu nbsp grouped' => ["1\u{00A0}234,56", 'eu', 123_456],
            'eu narrow nbsp grouped' => ["1\u{202F}234,56", 'eu', 123_456],
            'eu dot grouped' => ['1.234,56', 'eu', 123_456],
            'eu plain' => ['1234,5', 'eu', 123_450],
            'eu negative' => ['-1 234,56', 'eu', -123_456],
            'eu dot thousands only' => ['1.234', 'eu', 123_400],
            'eu currency' => ['R 1 234,56', 'eu', 123_456],
            'eu large' => ['12 345 678,90', 'eu', 1_234_567_890],
            'the float trap' => ['0,29', 'eu', 29],
        ];
    }

    #[DataProvider('rejected')]
    public function test_parse_localized_rejects_what_it_cannot_read_exactly(string $raw, string $locale): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::parseLocalized($raw, $locale);
    }

    public static function rejected(): array
    {
        return [
            'en three decimals' => ['12.345', 'en'],
            'en thousands dot' => ['1.234.567', 'en'],
            'en bad grouping' => ['12,34.56', 'en'],
            'eu three decimals' => ['12,345', 'eu'],
            'eu en-style value' => ['1,234.56', 'eu'],
            'eu bad grouping' => ['12.34,56', 'eu'],
            'letters' => ['twelve', 'en'],
            'empty' => ['', 'en'],
            'exponent' => ['1e3', 'en'],
            'two signs' => ['--5.00', 'en'],
            'unknown locale' => ['1.00', 'xx'],
        ];
    }

    #[DataProvider('detections')]
    public function test_detect_locale_only_commits_when_the_data_is_unambiguous(array $samples, ?string $locale, bool $ambiguous): void
    {
        $result = Money::detectLocale($samples);

        $this->assertSame($locale, $result['locale']);
        $this->assertSame($ambiguous, $result['ambiguous']);
    }

    public static function detections(): array
    {
        return [
            'en decimals' => [['12.50', '1,234.56', '-3.00'], 'en', false],
            'eu decimals' => [['12,50', '1 234,56', '-3,00'], 'eu', false],
            'eu dot grouped' => [['1.234,56', '12,00'], 'eu', false],
            'mixed evidence' => [['12.50', '12,50'], null, true],
            'only whole numbers means nothing to disagree about' => [['12', '1500', '-30'], 'en', false],
            'a lone 1,234 could be either' => [['1,234'], null, true],
            'a lone 1.234 could be either' => [['1.234'], null, true],
            'ambiguous resolved by another row' => [['1,234', '5.25'], 'en', false],
            'empty' => [[], 'en', false],
        ];
    }
}
