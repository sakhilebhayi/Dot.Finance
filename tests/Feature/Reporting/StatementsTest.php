<?php

namespace Tests\Feature\Reporting;

use App\Models\Accounting\AccountingPeriod;
use App\Services\Accounting\Periods;
use App\Services\Accounting\TrialBalance;
use App\Services\Reporting\ReportResult;
use App\Services\Reporting\ReportRunner;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsReportingLedger;
use Tests\TestCase;

/**
 * The statements reconcile to the trial balance and to each other. The books are the fixture's: six months of sales, rent
 * and fuel through the cash ledger (so they post through the real GL sync), opening cash of 5,000.00.
 *
 *   H1 income   75,025,000  (sales 75,000,000 + interest 25,000)
 *   H1 expenses 21,450,000  (rent 18,000,000 + fuel 3,450,000)
 *   H1 profit   53,575,000
 */
class StatementsTest extends TestCase
{
    use BuildsReportingLedger;
    use RefreshDatabase;

    private function run_(string $key, array $input = []): ReportResult
    {
        return app(ReportRunner::class)->run($this->ctx, $key, $input, CarbonImmutable::parse('2026-11-09'));
    }

    private function checksOk(ReportResult $r): void
    {
        $this->assertSame([], array_map(fn ($c) => $c['label'].': '.$c['detail'], $r->failedChecks()));
    }

    public function test_profit_and_loss_totals_and_percent_of_income(): void
    {
        $this->buildCompanyBooks();
        $r = $this->run_('profit-loss', ['from' => '2026-01-01', 'to' => '2026-06-30']);

        $this->assertSame(75_025_000, $r->cell('Total income', 'total'));
        $this->assertSame(21_450_000, $r->cell('Total expenses', 'total'));
        $this->assertSame(53_575_000, $r->cell('Net profit', 'total'));
        $this->assertSame(75_025_000, $r->cell('Gross profit', 'total'), 'no cost of sales in this chart use');
        $this->assertSame(10000, $r->cell('Total income', 'pct'));
        $this->assertSame(2399, $r->cell('6100 Rent', 'pct') ?? $r->cell('6500 Rent', 'pct'), '18,000,000 of 75,025,000 is 23.99%');
        $this->checksOk($r);
        $this->assertSame('Ties to the trial balance', $r->checks[0]['label']);
    }

    public function test_monthly_columns_add_up_to_the_total_and_comparison_shows_change(): void
    {
        $this->buildCompanyBooks();
        $r = $this->run_('profit-loss', ['from' => '2026-01-01', 'to' => '2026-06-30', 'monthly' => '1']);

        $months = array_values(array_filter(array_column($r->columns, 'key'), fn ($k) => str_starts_with($k, 'm:')));
        $this->assertCount(6, $months);
        foreach ($r->rows as $row) {
            if (in_array($row['type'], ['line', 'subtotal', 'total'], true) && isset($row['cells']['total'])) {
                $this->assertSame($row['cells']['total'], array_sum(array_map(fn ($m) => $row['cells'][$m] ?? 0, $months)), $row['label'].' months must add up to the total');
            }
        }
        $this->assertSame(10_000_000 + 25_000 * 0, $r->cell('Total income', 'm:2026-01'));
        $this->assertSame(11_000_000, $r->cell('Total income', 'm:2026-02'));
        $this->assertSame(11_000_000 - 3_500_000, $r->cell('Net profit', 'm:2026-02'));

        $c = $this->run_('profit-loss', ['from' => '2026-02-01', 'to' => '2026-02-28', 'compare' => 'previous']);
        $this->assertSame(11_000_000, $c->cell('Total income', 'total'));
        $this->assertSame(10_000_000, $c->cell('Total income', 'cmp'));
        $this->assertSame(1_000_000, $c->cell('Total income', 'chg'));
        $this->assertSame(1000, $c->cell('Total income', 'chg_pct'), '+10.00%');
    }

    public function test_balance_sheet_balances_ties_to_the_trial_balance_and_computes_current_year_earnings(): void
    {
        $this->buildCompanyBooks();
        $r = $this->run_('balance-sheet', ['to' => '2026-06-30']);

        $this->assertSame(54_075_000, $r->cell('Total assets', 'total'));
        $this->assertSame(0, $r->cell('Total liabilities', 'total'));
        $this->assertSame(53_575_000, $r->cell('Current year earnings (calculated)', 'total'));
        $this->assertSame(54_075_000, $r->cell('Total equity', 'total'));
        $this->assertSame($r->cell('Total assets', 'total'), $r->cell('Total liabilities and equity', 'total'));
        $this->checksOk($r);
        $labels = array_column($r->checks, 'key');
        $this->assertContains('ties_to_trial_balance', $labels);
        $this->assertContains('assets_equal_liabilities_plus_equity', $labels);

        // Comparative date: at 31 March the profit so far is lower and the sheet still balances.
        $c = $this->run_('balance-sheet', ['to' => '2026-06-30', 'compare' => 'previous']);
        $this->assertNotNull($c->cell('Total assets', 'cmp'));
        $this->assertSame($c->cell('Total assets', 'cmp'), $c->cell('Total liabilities and equity', 'cmp'));
    }

    public function test_year_end_closing_moves_the_year_into_retained_earnings_without_changing_the_sheet(): void
    {
        $this->buildCompanyBooks();
        LedgerContext::run($this->ctx, fn () => app(Periods::class)->closeThrough($this->ctx, CarbonImmutable::parse('2026-12-31'), $this->owner));
        $this->assertNotNull(AccountingPeriod::query()->forContext($this->ctx)->where('is_year_end', true)->value('closing_entry_id'));

        // On the last day of the closed year the sheet still reads as the year: earnings are shown as earnings.
        $atYearEnd = $this->run_('balance-sheet', ['to' => '2026-12-31']);
        $this->assertSame(53_575_000, $atYearEnd->cell('Current year earnings (calculated)', 'total'));
        $this->assertSame(54_075_000, $atYearEnd->cell('Total equity', 'total'));
        $this->checksOk($atYearEnd);

        // In the next year those earnings are retained earnings and this year's earnings start at zero.
        $next = $this->run_('balance-sheet', ['to' => '2027-01-31']);
        $this->assertSame(0, $next->cell('Current year earnings (calculated)', 'total'));
        $this->assertSame(53_575_000, $next->cell('3100 Retained earnings', 'total'));
        $this->assertSame(54_075_000, $next->cell('Total equity', 'total'));
        $this->checksOk($next);

        // A closed year's profit and loss still reads as the year.
        $this->assertSame(53_575_000, $this->run_('profit-loss', ['from' => '2026-01-01', 'to' => '2026-12-31'])->cell('Net profit', 'total'));
    }

    public function test_unclosed_earlier_years_are_shown_as_their_own_line(): void
    {
        $this->buildCompanyBooks();
        // A transaction in the following year while 2026 was never closed.
        $this->tx($this->bank, \App\Models\Category::query()->forContext($this->ctx)->where('name', 'Consulting')->first(), 'income', 2_000_000, '2027-02-10');
        app(\App\Services\Accounting\GlSync::class)->flush();
        $this->artisan('finance:gl:post-pending');

        $r = $this->run_('balance-sheet', ['to' => '2027-03-31']);
        $this->assertSame(53_575_000, $r->cell('Earnings of earlier years, not yet closed to retained earnings', 'total'));
        $this->assertSame(2_000_000, $r->cell('Current year earnings (calculated)', 'total'));
        $this->assertSame($r->cell('Total assets', 'total'), $r->cell('Total liabilities and equity', 'total'));
        $this->checksOk($r);
    }

    public function test_cash_flow_statement_direct_and_indirect_agree_and_reconcile_to_cash(): void
    {
        $this->buildCompanyBooks();
        $customer = $this->party('Acme Retail');
        $this->invoice('2026-05-10', 1_000_000, $customer, null, 7001, 150_000); // unpaid: profit up, cash unchanged
        $this->receipt('2026-06-05', 575_000, $customer, 7001);

        foreach (['indirect', 'direct'] as $view) {
            $r = $this->run_('cash-flow-statement', ['from' => '2026-01-01', 'to' => '2026-06-30', 'view' => $view]);
            $this->checksOk($r);
            $net = $r->cell('Closing cash and bank balances', 'total');
            $this->assertSame(54_075_000 + 575_000, $net, "{$view}: opening 0 + profit 54,575,000 + 500,000 opening equity - receivable 575,000 + VAT 150,000 ... equals closing cash");
            $this->assertSame(0, $r->cell('Opening cash and bank balances', 'total'));
        }

        $indirect = $this->run_('cash-flow-statement', ['from' => '2026-01-01', 'to' => '2026-06-30']);
        $this->assertSame(54_575_000, $indirect->cell('Net profit for the period', 'total'));
        $this->assertSame(54_075_000 + 575_000, $indirect->cell('Closing cash and bank balances', 'total'));
        $this->assertSame(500_000, $indirect->cell('3900 Opening balance equity', 'total'), 'the opening balance is a financing line');
        $this->assertSame(-575_000, $indirect->cell('1100 Accounts receivable (increase)', 'total'), 'the invoice is 575,000 still owed');
        $this->assertSame(150_000, $indirect->cell('2100 VAT output (payable)', 'total'));
        $this->assertSame(54_575_000 - 575_000 + 150_000, $indirect->cell('Cash from operating activities', 'total'));
    }

    public function test_a_period_in_the_middle_has_opening_cash_from_the_ledger(): void
    {
        $this->buildCompanyBooks();
        $r = $this->run_('cash-flow-statement', ['from' => '2026-04-01', 'to' => '2026-06-30']);
        $this->checksOk($r);
        // Opening 500,000 + Jan to Mar (income 33,025,000 - expenses 10,000,000 + ...) is whatever the ledger says.
        $tb = app(TrialBalance::class)->asOf($this->ctx, '2026-03-31');
        $cash = collect($tb['rows'])->filter(fn ($row) => in_array($row['account']->subtype, ['bank', 'cash'], true))->sum(fn ($row) => $row['debit_balance'] - $row['credit_balance']);
        $this->assertSame($cash, $r->cell('Opening cash and bank balances', 'total'));
        $this->assertGreaterThan(0, $cash);
    }

    public function test_the_trial_balance_report_balances_and_every_amount_traces(): void
    {
        $this->buildCompanyBooks();
        $r = $this->run_('trial-balance', ['to' => '2026-06-30']);
        $total = $r->row('Total');
        $this->assertSame($total['cells']['debit'], $total['cells']['credit']);
        $this->checksOk($r);
    }

    public function test_the_general_ledger_runs_a_balance_per_account_and_closes_to_the_trial_balance(): void
    {
        $this->buildCompanyBooks();
        $rent = $this->glCode('6500'); // the account the Rent category posts to
        $r = $this->run_('general-ledger', ['from' => '2026-02-01', 'to' => '2026-03-31', 'account' => (string) $rent->id]);
        $this->assertSame(3_000_000, $r->cell('Opening balance', 'balance'));
        $this->assertSame(9_000_000, $r->cell('Closing balance', 'balance'));
        $this->assertSame(6_000_000, $r->cell('Closing balance', 'debit'));
        $lines = array_values(array_filter($r->rows, fn ($row) => isset($row['cells']['ref'])));
        $this->assertCount(2, $lines);
        $this->assertSame(6_000_000, end($lines)['cells']['balance'] - 3_000_000);
    }

    public function test_a_posting_the_cash_ledger_has_but_the_journals_lack_turns_the_bank_badge_red_with_the_amount(): void
    {
        $this->buildCompanyBooks();
        $this->tx($this->bank, \App\Models\Category::query()->forContext($this->ctx)->where('name', 'Rent')->first(), 'expense', 123_400, '2026-06-20');

        $r = $this->run_('trial-balance', ['to' => '2026-06-30']);
        $bank = collect($r->checks)->firstWhere('key', 'ties_to_bank_balances');
        $this->assertFalse($bank['ok']);
        $this->assertSame(123_400, $bank['difference_cents']);
        $this->assertStringContainsString('Business cheque', $bank['detail']);

        $page = $this->inCompany($this->owner, $this->company)->get(route('reports.trial-balance', ['to' => '2026-06-30']));
        $page->assertOk()->assertSee('Ties to bank balances')->assertSee('out by');
    }

    public function test_statements_before_accounting_started_say_there_is_nothing_to_compare(): void
    {
        $this->buildCompanyBooks();
        $r = $this->run_('balance-sheet', ['to' => '2025-06-30']);
        $bank = collect($r->checks)->firstWhere('key', 'ties_to_bank_balances');
        $this->assertTrue($bank['ok']);
        $this->assertStringContainsString('Accounting started', $bank['detail']);
    }
}
