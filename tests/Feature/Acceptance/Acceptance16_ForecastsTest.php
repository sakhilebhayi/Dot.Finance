<?php

namespace Tests\Feature\Acceptance;

use App\Models\Account;
use App\Models\Planning\Scenario;
use App\Services\Planning\Forecaster;
use App\Services\Reporting\Books;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Owner criterion 16: forecasts distinguish actuals from projections. Seven real months of postings (six completed ones) are the history; every forecast
 * page labels actual months "actual" and future months "projected" (a visible pill and a projected-cell marker class, which the CSS
 * hatches), a forecast says how wrong it typically is, is repeatable, and no forecast, cash projection, scenario or affordability
 * check ever writes to the books. There is no data-* attribute on these pages: the marker is the class and the words.
 */
class Acceptance16_ForecastsTest extends AcceptanceCase
{
    private const BOOK_TABLES = ['journal_entries', 'journal_lines', 'transactions', 'accounts', 'payments', 'payment_allocations', 'sales_documents', 'bills', 'gl_accounts', 'accounting_periods'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIntegration();
        $this->asOwner()->post(route('accounts.store'), ['name' => 'History', 'type' => 'checking', 'currency' => 'ZAR', 'opening_balance' => '0.00', 'opening_balance_date' => '2026-04-01'])->assertSessionHasNoErrors();
        $history = Account::query()->forContext($this->ledger)->where('name', 'History')->firstOrFail();
        foreach (range(0, 6) as $i) {                 // May to November 2026: income rising by 1,000 a month, expenses flat
            $month = CarbonImmutable::parse('2026-05-15')->addMonthsNoOverflow($i);
            $this->asOwner()->post(route('transactions.store'), ['account_id' => $history->id, 'description' => 'Fees '.$month->format('M'), 'amount' => number_format(10_000 + 1_000 * $i, 2, '.', ''), 'type' => 'income', 'date' => $month->toDateString()])->assertSessionHasNoErrors();
            $this->asOwner()->post(route('transactions.store'), ['account_id' => $history->id, 'description' => 'Costs '.$month->format('M'), 'amount' => '4000.00', 'type' => 'expense', 'date' => $month->toDateString()])->assertSessionHasNoErrors();
        }
    }

    private function books(): string
    {
        $out = [];
        foreach (self::BOOK_TABLES as $t) {
            $out[$t] = DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }

        return md5(json_encode($out));
    }

    public function test_the_forecast_page_labels_history_as_actual_and_the_future_as_projected_with_its_typical_error(): void
    {
        $html = $this->asOwner()->get(route('planning.forecasts.index', ['months' => 3]))->assertOk()->getContent();

        $this->assertSame(12, preg_match_all('#fx-pill[^>]*>\s*(?:<svg.*?</svg>\s*)?actual\s*</#s', $html), 'six actual months for revenue and six for expenses');
        $this->assertSame(6, preg_match_all('#fx-pill[^>]*>\s*(?:<svg.*?</svg>\s*)?projected\s*</#s', $html), 'three projected months for each');
        $this->assertSame(6, substr_count($html, 'class="rp-projected"'), 'and every projected row carries the projected marker');
        $this->assertStringContainsString('Nov 2026', $html, 'the first projected month is the month in progress: only completed months are actuals');
        $this->assertStringNotContainsString('data-projected', $html);
        $this->assertStringContainsString('A forecast is an extension of the past, not a promise', $html);
        $this->assertStringContainsString('Typical miss on the last three months', $html);
        $this->assertStringContainsString('Likely range', $html);
        // no projected row is dated in a completed month
        preg_match_all('#<tr class="rp-projected"><th[^>]*>([A-Za-z]{3} \d{4})</th>#', $html, $m);
        foreach ($m[1] as $label) {
            $this->assertTrue(CarbonImmutable::parse('1 '.$label)->gte(CarbonImmutable::parse('2026-11-01')), "{$label} is not a completed month");
        }
    }

    public function test_the_forecast_is_deterministic_and_matches_the_method_worked_out_by_hand(): void
    {
        $one = $this->asOwner()->get(route('planning.forecasts.index', ['months' => 3, 'method' => 'linear_trend']))->assertOk()->getContent();
        $two = $this->asOwner()->get(route('planning.forecasts.index', ['months' => 3, 'method' => 'linear_trend']))->assertOk()->getContent();
        $strip = fn (string $h) => preg_replace('/csrf-token" content="[^"]+"|nonce="[^"]+"|data-csrf="[^"]+"|name="_token" value="[^"]+"/', '', $h);
        $this->assertSame($strip($one), $strip($two));

        // income is 10,000 11,000 ... 15,000: a straight line continues at 16,000 17,000 18,000
        $f = new Forecaster(app(Books::class));
        $this->assertSame(1_600_000, $f->predict('linear_trend', [1_000_000, 1_100_000, 1_200_000, 1_300_000, 1_400_000, 1_500_000], 1));
        $this->assertStringContainsString('16,000.00', $one);
        $this->assertStringContainsString('17,000.00', $one);
        $this->assertStringContainsString('18,000.00', $one);
    }

    public function test_nothing_that_forecasts_or_projects_writes_to_the_books(): void
    {
        $before = $this->books();
        $auditBefore = $this->auditRows()->count();

        foreach ([
            route('planning.forecasts.index'), route('planning.forecasts.index', ['months' => 12, 'method' => 'seasonal_naive']), route('planning.cash.index'), route('planning.cash.index', ['horizon' => 'months_12', 'buffer' => '5000.00']),
            route('forecast.index'), route('planning.afford', ['amount' => '2500.00', 'on' => '2026-12-05', 'mode' => 'once']), route('planning.scenarios.index'),
        ] as $url) {
            $this->asOwner()->get($url)->assertOk();
        }

        $this->asOwner()->post(route('planning.scenarios.store'), ['name' => 'Slow customers', 'horizon' => 'weeks_13', 'assumptions' => [['type' => 'delay_receipts', 'days' => '14']]])->assertSessionHasNoErrors();
        $scenario = Scenario::query()->forContext($this->ledger)->firstOrFail();
        $this->asOwner()->get(route('planning.scenarios.show', $scenario))->assertOk()->assertSee('projected, not guaranteed');
        $this->asOwner()->get(route('planning.cash.index', ['scenario' => $scenario->id]))->assertOk();

        $this->assertSame($before, $this->books(), 'forecasting, projecting, scenarios and affordability checks wrote nothing to the books');
        $this->assertSame(0, $this->auditRows()->count() - $auditBefore - $this->auditRows(null, 'scenario')->count(), 'and only the scenario itself was audited');
    }

    public function test_the_profit_and_loss_for_a_future_month_stays_empty_after_forecasting_it(): void
    {
        $this->asOwner()->get(route('planning.forecasts.index', ['months' => 6]))->assertOk();
        $december = $this->report('profit-loss', ['from' => '2026-12-01', 'to' => '2026-12-31']);
        $this->assertSame(0, (int) ($december->cell('Total income', 'total') ?? 0), 'December has no actual income: the forecast is not in the books');
        $this->assertSame(0, (int) ($december->cell('Net profit', 'total') ?? 0));
    }

    public function test_the_cash_projection_labels_everything_it_shows_as_projected_and_the_legacy_forecast_separates_actual_from_projected(): void
    {
        $this->issueInvoice($this->acme, [['description' => 'Retainer', 'quantity' => '1', 'unit_cents' => 500_000, 'tax_code' => 'ZA_VAT_STD']], '2026-11-20');
        $cash = $this->asOwner()->get(route('planning.cash.index'))->assertOk()->getContent();
        $this->assertStringContainsString('projected, not guaranteed', $cash);
        $this->assertStringContainsString('Week by week (projected)', $cash);
        $this->assertGreaterThan(0, substr_count($cash, 'rp-projected'));
        $this->assertStringContainsString('INV-00001', $cash, 'the open invoice is a projected inflow, with its source');

        $legacy = $this->asOwner()->get(route('forecast.index'))->assertOk()->getContent();
        $this->assertStringContainsString('projected, not actual', $legacy);
    }
}
