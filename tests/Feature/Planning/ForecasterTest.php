<?php

namespace Tests\Feature\Planning;

use App\Services\Planning\Forecaster;
use App\Services\Reporting\Books;
use App\Services\Reporting\ReportRunner;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsReportingLedger;
use Tests\TestCase;

class ForecasterTest extends TestCase
{
    use BuildsReportingLedger;
    use RefreshDatabase;

    private function series(array $values, string $start = '2024-01'): array
    {
        $out = [];
        foreach ($values as $i => $v) {
            $out[CarbonImmutable::parse($start.'-01')->addMonthsNoOverflow($i)->format('Y-m')] = $v;
        }

        return $out;
    }

    public function test_the_methods_are_checked_by_hand(): void
    {
        $f = new Forecaster(app(Books::class));
        $line = array_map(fn ($i) => (100 + 10 * $i) * 1000, range(0, 11)); // 100,000 rising by 10,000 a month, 12 months

        $this->assertSame(200_000, $f->predict('trailing_average', $line, 1), '(190,000 + 200,000 + 210,000) / 3');
        $this->assertSame(220_000, $f->predict('linear_trend', $line, 1), 'a straight line continues');
        $this->assertSame(250_000, $f->predict('linear_trend', $line, 4));
        $this->assertSame(1, $f->predict('trailing_average', [1, 1, 2], 1), '4/3 rounds to 1');
        $this->assertSame(0, $f->predict('linear_trend', [500, 400, 300, 200, 100, 50], 6), 'a forecast never goes below zero');
        $year = array_map(fn ($i) => $i * 100, range(1, 12));
        $this->assertSame(100, $f->predict('seasonal_naive', $year, 1), 'next month repeats the same month of last year');
        $this->assertSame(200, $f->predict('seasonal_naive', $year, 2));
        $this->assertSame(100, $f->predict('seasonal_naive', $year, 13), 'and a year further on, the same again');
    }

    public function test_back_test_errors_and_the_best_method_are_computed_and_deterministic(): void
    {
        $f = new Forecaster(app(Books::class));
        $history = $this->series(array_map(fn ($i) => (100 + 10 * $i) * 1000, range(0, 11)));

        $a = $f->forecast($history, 3);
        $b = $f->forecast($history, 3);
        $this->assertSame($a, $b, 'the same history always gives the same forecast');

        $this->assertTrue($a['ok']);
        $this->assertSame('linear_trend', $a['method']);
        $this->assertSame(['2025-01', '2025-02', '2025-03'], array_column($a['forecast'], 'month'));
        $this->assertSame([220_000, 230_000, 240_000], array_column($a['forecast'], 'cents'));

        $tests = collect($a['backtest'])->keyBy('method');
        $this->assertSame(0, $tests['linear_trend']['mae_cents']);
        $this->assertSame(20_000, $tests['trailing_average']['mae_cents'], 'three months each 20,000 short');
        $this->assertSame(60_000, $tests['trailing_average']['abs_error_cents']);
        $this->assertSame(Money_ratio(60_000, 190_000 + 200_000 + 210_000), $tests['trailing_average']['error_bp'], 'error as a share of what happened, in basis points');
        $this->assertCount(3, $tests['trailing_average']['points']);
        $this->assertSame(['predicted_cents' => 170_000, 'actual_cents' => 190_000, 'error_cents' => -20_000], collect($tests['trailing_average']['points'][0])->only(['predicted_cents', 'actual_cents', 'error_cents'])->all());
        $this->assertArrayNotHasKey('seasonal_naive', $tests->all(), 'seasonal needs 24 months');
        $this->assertFalse($a['seasonal_available']);
        $this->assertStringContainsString('look like the recent past', implode(' ', $a['assumptions']));

        // The person may pick another method; the forecast follows and says which one is best.
        $chosen = $f->forecast($history, 2, 'trailing_average');
        $this->assertSame('trailing_average', $chosen['method']);
        $this->assertSame('linear_trend', $chosen['best_method']);
        $this->assertSame([200_000, 200_000], array_column($chosen['forecast'], 'cents'));
        $this->assertSame(20_000, $chosen['band_cents']);
    }

    public function test_seasonal_naive_is_offered_and_wins_on_a_seasonal_history_of_two_years(): void
    {
        $f = new Forecaster(app(Books::class));
        $values = [];
        foreach (range(0, 23) as $i) {
            $values[] = 1_000_000 + ($i % 12) * 100_000;
        }
        $r = $f->forecast($this->series($values), 3);

        $this->assertTrue($r['seasonal_available']);
        $this->assertSame('seasonal_naive', $r['method']);
        $this->assertSame([1_000_000, 1_100_000, 1_200_000], array_column($r['forecast'], 'cents'));
        $this->assertSame(0, collect($r['backtest'])->firstWhere('method', 'seasonal_naive')['mae_cents']);
    }

    public function test_it_refuses_rather_than_guesses_with_too_little_history_or_unsafe_amounts(): void
    {
        $f = new Forecaster(app(Books::class));
        $r = $f->forecast($this->series([1, 2, 3, 4, 5]), 3);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('at least 6 months', $r['reason']);
        $this->assertFalse($f->forecast([], 3)['ok']);
        $this->assertFalse($f->forecast($this->series(array_fill(0, 8, 200_000_000_000)), 3)['ok']);
    }

    public function test_history_is_the_ledgers_complete_months_with_gaps_filled_with_zero(): void
    {
        $this->buildCompanyBooks();
        $request = app(ReportRunner::class)->request($this->ctx, 'profit-loss', []);
        $f = app(Forecaster::class);

        $income = $f->history($request, 'income', CarbonImmutable::parse('2026-07-09'));
        $this->assertSame(['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06'], array_keys($income));
        $this->assertSame(10_000_000, $income['2026-01']);
        $this->assertSame(12_025_000, $income['2026-03'], 'sales plus interest');

        // Later in the year, months with nothing in them count as zero, and the current month is left out (it is incomplete).
        $later = $f->history($request, 'income', CarbonImmutable::parse('2026-09-15'));
        $this->assertSame(['2026-07' => 0, '2026-08' => 0], array_slice($later, -2, null, true));
        $this->assertArrayNotHasKey('2026-09', $later);

        $expense = $f->history($request, 'expense', CarbonImmutable::parse('2026-07-09'));
        $this->assertSame(3_000_000 + 450_000, $expense['2026-01']);
    }

    public function test_the_page_shows_actual_and_projected_months_apart_and_refuses_with_too_little_history(): void
    {
        $this->buildCompanyBooks();
        $page = $this->inCompany($this->owner, $this->company)->get(route('planning.forecasts.index'));
        $page->assertOk()->assertSee('Forecasts')->assertSee('projected')->assertSee('actual')->assertSee('back-test')->assertSee('Typical miss')->assertSee('not a promise');

        $thin = $this->user();
        $company = $this->company($thin, 'Thin Co');
        $ctx = $this->companyContext($company);
        $account = $this->companyAccount($company, ['opening_balance_cents' => 0, 'opening_balance_date' => '2026-01-01']);
        $this->tx($account, $this->companyCategory($company, ['name' => 'Sales', 'type' => 'income']), 'income', 1_000, '2026-02-01');
        $this->enableAccounting($ctx, 'ZAR');
        $this->inCompany($thin, $company)->get(route('planning.forecasts.index'))->assertOk()->assertSee('Not enough history');
    }
}

function Money_ratio(int $n, int $d): int
{
    return Money::divideRound($n * 10000, $d);
}
