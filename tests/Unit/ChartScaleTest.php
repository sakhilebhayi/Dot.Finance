<?php

namespace Tests\Unit;

use App\Support\Ui\ChartScale;
use PHPUnit\Framework\TestCase;

class ChartScaleTest extends TestCase
{
    public function test_a_balance_between_718k_and_811k_gets_an_axis_near_the_data_with_round_gridlines(): void
    {
        $s = ChartScale::near(718_000_00, 811_000_00);

        $this->assertGreaterThan(600_000_00, $s['min'], 'the axis does not start at zero');
        $this->assertLessThanOrEqual(718_000_00, $s['min']);
        $this->assertGreaterThanOrEqual(811_000_00, $s['max']);
        $this->assertFalse($s['fromZero']);
        $this->assertGreaterThanOrEqual(4, count($s['ticks']));
        $this->assertLessThanOrEqual(9, count($s['ticks']));
        $this->assertSame(0, $s['min'] % $s['step']);
        foreach ($s['ticks'] as $tick) {
            $this->assertSame(0, $tick % $s['step']);
        }
        $this->assertSame($s['min'], $s['ticks'][0]);
        $this->assertSame($s['max'], end($s['ticks']));
    }

    public function test_a_flat_line_gets_a_symmetric_axis_and_says_it_is_flat(): void
    {
        $s = ChartScale::near(-300_000, -300_000);

        $this->assertTrue($s['flat']);
        $this->assertLessThan(-300_000, $s['min']);
        $this->assertGreaterThan(-300_000, $s['max']);
        $this->assertTrue(in_array(-300_000, range($s['min'], $s['max'], $s['step']), true) || $s['min'] < -300_000);
    }

    public function test_a_range_that_stays_positive_stops_at_zero_instead_of_crossing_it_by_padding(): void
    {
        $s = ChartScale::near(2_00, 100_00);

        $this->assertSame(0, $s['min']);
        $this->assertTrue($s['fromZero']);
    }

    public function test_a_range_that_really_crosses_zero_is_marked_and_keeps_the_zero_gridline(): void
    {
        $s = ChartScale::near(-250_000_00, 400_000_00);

        $this->assertTrue($s['crossesZero']);
        $this->assertContains(0, $s['ticks']);
    }

    public function test_the_step_is_never_below_one_currency_unit_and_the_axis_never_has_a_runaway_number_of_ticks(): void
    {
        foreach ([[0, 0], [1, 2], [0, 1_000_000_000_00], [-5, 5], [99_999_999_999_999, 99_999_999_999_999]] as [$a, $b]) {
            $s = ChartScale::near($a, $b);
            $this->assertGreaterThanOrEqual(100, $s['step']);
            $this->assertLessThanOrEqual(12, count($s['ticks']));
            $this->assertLessThanOrEqual(min($a, $b), $s['min'], 'the axis starts at or below the lowest figure');
            $this->assertGreaterThanOrEqual(max($a, $b), $s['max'], 'and ends at or above the highest');
        }
    }
}
