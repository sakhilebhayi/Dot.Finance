<?php

namespace Tests\Unit\Polish;

use App\Support\BudgetProgress;
use App\Support\FigureWords;
use PHPUnit\Framework\TestCase;

/** R-44 polish (jury defect 5): a budget past its limit says by how much, and the bar shows the overrun. Integer arithmetic only. */
class BudgetProgressTest extends TestCase
{
    public function test_an_overspent_budget_says_the_overspend_amount_and_percentage_instead_of_100_percent(): void
    {
        // Fuel: 12,730.00 spent against a 4,000.00 limit (the jury's example).
        $p = BudgetProgress::for(1_273_000, 400_000, 'ZAR');

        $this->assertTrue($p['over']);
        $this->assertSame(873_000, $p['over_cents']);
        $this->assertSame(218, $p['over_pct']);
        $this->assertSame('ZAR 8,730.00 over the limit (218% more than planned)', $p['caption']);
        $this->assertStringNotContainsString('100% used', $p['caption']);
    }

    public function test_the_bar_is_scaled_to_the_larger_of_spend_and_limit_so_the_overrun_is_visible(): void
    {
        $p = BudgetProgress::for(1_273_000, 400_000, 'ZAR');

        $this->assertSame(31, $p['limit_mark_pct'], 'the limit sits at 31% of the bar');
        $this->assertSame(31, $p['within_pct']);
        $this->assertSame(69, $p['overrun_pct'], 'the rest of the bar is the part past the limit');
        $this->assertSame(100, $p['within_pct'] + $p['overrun_pct']);
    }

    public function test_a_budget_under_its_limit_says_what_is_left(): void
    {
        $p = BudgetProgress::for(2_515_000, 5_500_000, 'ZAR');

        $this->assertFalse($p['over']);
        $this->assertSame(45, $p['used_pct']);
        $this->assertSame(2_985_000, $p['left_cents']);
        $this->assertSame('45% used, ZAR 29,850.00 left', $p['caption']);
        $this->assertSame(0, $p['overrun_pct']);
    }

    public function test_exactly_at_the_limit_is_not_an_overspend(): void
    {
        $p = BudgetProgress::for(300_000, 300_000, 'ZAR');

        $this->assertFalse($p['over']);
        $this->assertTrue($p['at_limit']);
        $this->assertSame(0, $p['over_cents']);
        $this->assertSame('Exactly at the limit, nothing left', $p['caption']);
    }

    public function test_one_cent_over_is_over_and_a_zero_limit_does_not_divide_by_zero(): void
    {
        $this->assertTrue(BudgetProgress::for(300_001, 300_000)['over']);
        $this->assertSame(1, BudgetProgress::for(300_001, 300_000)['over_cents']);

        $zero = BudgetProgress::for(500, 0);
        $this->assertFalse($zero['over']);
        $this->assertSame('No limit set', $zero['caption']);
    }

    public function test_a_receivable_that_is_negative_is_a_credit_on_account_never_owes_you_in_brackets(): void
    {
        $credit = FigureWords::owedBy(-52_200);
        $this->assertSame(['label' => 'Credit on account', 'cents' => 52_200, 'credit' => true], $credit);

        $owes = FigureWords::owedBy(11_833_500);
        $this->assertSame(['label' => 'Owes you', 'cents' => 11_833_500, 'credit' => false], $owes);

        $this->assertSame('Credit with supplier', FigureWords::owedTo(-100)['label']);
        $this->assertSame('You owe', FigureWords::owedTo(100)['label']);
    }

    public function test_axis_labels_are_short_and_integer_only(): void
    {
        $this->assertSame('0', FigureWords::axis(0));
        $this->assertSame('950', FigureWords::axis(95_000));
        $this->assertSame('1.5k', FigureWords::axis(150_000));
        $this->assertSame('58k', FigureWords::axis(5_897_637));
        $this->assertSame('1.3m', FigureWords::axis(13_000_000_00 / 10));
        $this->assertSame('2m', FigureWords::axis(200_000_000));
        $this->assertSame('-4.2k', FigureWords::axis(-420_000));
    }

    public function test_gridline_steps_are_round_and_the_top_line_is_above_the_tallest_bar(): void
    {
        // The jury's chart: a peak of 235,905.50 gave ticks of 58k, 117k, 176k. Now 60k steps: 0, 60k, 120k, 180k, 240k.
        $this->assertSame(6_000_000, FigureWords::niceStep(23_590_550));
        $this->assertSame(['0', '60k', '120k', '180k', '240k'], array_map(fn ($q) => FigureWords::axis(6_000_000 * $q), [0, 1, 2, 3, 4]));
        $this->assertGreaterThanOrEqual(23_590_550, 6_000_000 * 4);

        $this->assertSame(100, FigureWords::niceStep(1), 'never a zero step');
        $this->assertSame(25_000, FigureWords::niceStep(100_000), 'R1,000 over four steps of R250');
        foreach ([1, 99, 1_000, 123_456, 9_999_999, 5_000_000_000] as $peak) {
            $this->assertGreaterThanOrEqual($peak, FigureWords::niceStep($peak) * 4, "the scale covers {$peak}");
        }
    }
}
