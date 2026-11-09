<?php

namespace Tests\Unit\Intelligence;

use App\Services\Intelligence\NumberGuard;
use PHPUnit\Framework\TestCase;

class NumberGuardTest extends TestCase
{
    private const FIGURES = "You spent ZAR 1,234.50 in November 2026.\nFuel ZAR 450.00\nUp 12%\nOn 2026-11-09";

    public function test_a_rewording_with_only_the_computed_numbers_passes(): void
    {
        $this->assertSame([], NumberGuard::violations('November came to ZAR 1,234.50, with fuel at ZAR 450.00, up 12% on the month.', self::FIGURES));
        $this->assertSame([], NumberGuard::violations('R1 234,50 went out in 2026.', self::FIGURES));
    }

    public function test_an_invented_changed_or_rounded_number_is_rejected(): void
    {
        $this->assertNotSame([], NumberGuard::violations('You spent about ZAR 1,200 in November.', self::FIGURES));
        $this->assertNotSame([], NumberGuard::violations('That is 13% more.', self::FIGURES));
        $this->assertNotSame([], NumberGuard::violations('You spent ZAR 1,234 in November.', self::FIGURES), 'a number must match whole');
        $this->assertNotSame([], NumberGuard::violations('Fuel was ZAR 234.50.', self::FIGURES), 'a part of a grouped number is not the number');
    }

    public function test_number_words_that_the_figures_did_not_use_are_rejected(): void
    {
        $this->assertNotSame([], NumberGuard::violations('You spent double that in November.', self::FIGURES));
        $this->assertNotSame([], NumberGuard::violations('Roughly a thousand rand went out.', self::FIGURES));
        $this->assertSame([], NumberGuard::violations('Your spending went up.', self::FIGURES));
        // A word the computed text itself used is fine.
        $this->assertSame([], NumberGuard::violations('Just one thing stands out.', "There is one thing worth a look.\n"));
    }

    public function test_links_and_essays_are_rejected(): void
    {
        $this->assertNotSame([], NumberGuard::violations('See https://example.com for more.', self::FIGURES));
        $this->assertNotSame([], NumberGuard::violations('Read [this](https://x.test).', self::FIGURES));
        $this->assertNotSame([], NumberGuard::violations(str_repeat('word ', 200), self::FIGURES));
    }

    public function test_separators_and_leading_zeros_are_normalised(): void
    {
        $this->assertSame(['123450'], NumberGuard::numbers('ZAR 1,234.50'));
        $this->assertSame(['123450'], NumberGuard::numbers("R1\u{00A0}234,50"));
        $this->assertSame(['9'], NumberGuard::numbers('09'));
        $this->assertSame(['0'], NumberGuard::numbers('0'));
    }
}
