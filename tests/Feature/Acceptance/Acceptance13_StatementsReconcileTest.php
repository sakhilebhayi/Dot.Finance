<?php

namespace Tests\Feature\Acceptance;

use App\Models\Account;
use App\Models\Accounting\JournalEntry;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LiftsJournalGuards;

/**
 * Owner criterion 13: the financial statements reconcile with the underlying records. On the reconciled month: assets equal liabilities
 * plus equity, the profit and loss equals the trial balance, the cash-flow statement closes at the bank account's balance, the same
 * holds at other dates, in both cash-flow views, and the pages say so. A ledger that was corrupted on purpose is reported as out of
 * balance, not smoothed over.
 */
class Acceptance13_StatementsReconcileTest extends AcceptanceCase
{
    use LiftsJournalGuards;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMonth();
    }

    public function test_assets_equal_liabilities_plus_equity_and_the_earnings_are_the_profit_and_loss_profit(): void
    {
        $bs = $this->report('balance-sheet', ['to' => '2026-11-30']);
        $this->assertSame(12_928_000, $bs->cell('Total assets', 'total'));
        $this->assertSame(1_400_000 + 11_528_000, $bs->cell('Total liabilities and equity', 'total'), 'A = L + E, by hand: 129,280.00');
        $pl = $this->report('profit-loss', ['from' => '2026-11-01', 'to' => '2026-11-30']);
        $this->assertSame($pl->cell('Net profit', 'total'), $bs->cell('Current year earnings (calculated)', 'total'));
        $this->assertReportChecksPass($bs);
        $this->assertReportChecksPass($pl);
    }

    public function test_the_equation_holds_at_every_date_of_the_month_not_only_at_the_end(): void
    {
        foreach (['2026-11-03', '2026-11-10', '2026-11-12', '2026-11-18', '2026-11-25', '2026-11-30'] as $date) {
            $bs = $this->report('balance-sheet', ['to' => $date]);
            $this->assertSame($bs->cell('Total assets', 'total'), $bs->cell('Total liabilities and equity', 'total'), "balance sheet at {$date}");
            $this->assertReportChecksPass($bs);
            $tb = $this->report('trial-balance', ['to' => $date]);
            $this->assertSame($tb->cell('Total', 'debit'), $tb->cell('Total', 'credit'), "trial balance at {$date}");
        }
    }

    public function test_the_profit_and_loss_equals_the_trial_balances_income_and_expense_accounts(): void
    {
        $tb = $this->report('trial-balance', ['to' => '2026-11-30']);
        $pl = $this->report('profit-loss', ['from' => '2026-11-01', 'to' => '2026-11-30']);

        $income = 0;
        $expense = 0;
        foreach ($tb->rows as $row) {
            $net = (int) ($row['cells']['debit'] ?? 0) - (int) ($row['cells']['credit'] ?? 0);
            match ($row['cells']['class'] ?? null) {
                'Income' => $income -= $net,
                'Expense', 'Cost of sales' => $expense += $net,
                default => null,
            };
        }
        $this->assertSame($pl->cell('Total income', 'total'), $income, 'income from the trial balance is the P&L income');
        $this->assertSame($pl->cell('Total expenses', 'total') + (int) ($pl->cell('Total cost of sales', 'total') ?? 0), $expense, 'expenses likewise');
        $this->assertSame(3_053_500 - 1_525_500, $pl->cell('Net profit', 'total'));
    }

    public function test_the_cash_flow_statement_closes_at_the_bank_accounts_balance_in_both_views(): void
    {
        $balance = Account::query()->forContext($this->ledger)->withBalance()->findOrFail($this->bank->id)->balanceCents();
        foreach (['indirect', 'direct'] as $view) {
            $cf = $this->report('cash-flow-statement', ['from' => '2026-11-01', 'to' => '2026-11-30', 'view' => $view]);
            $this->assertSame(11_929_000, $cf->cell('Closing cash and bank balances', 'total'), "{$view} closes at the statement balance");
            $this->assertSame($balance, $cf->cell('Closing cash and bank balances', 'total'), "{$view} closes at the cash ledger's balance");
            $this->assertSame(10_000_000, $cf->cell('Opening cash and bank balances', 'total'));
            $this->assertReportChecksPass($cf);
        }
    }

    public function test_the_pages_show_the_reconciliation_checks_and_the_export_carries_the_same_figures(): void
    {
        foreach (['profit-loss', 'balance-sheet', 'cash-flow-statement', 'trial-balance'] as $key) {
            $page = $this->asOwner()->get(route('reports.'.$key, ['from' => '2026-11-01', 'to' => '2026-11-30']))->assertOk()->getContent();
            $this->assertStringNotContainsString('OUT OF BALANCE', $page, $key);
            $this->assertMatchesRegularExpression('/Ties to|Agrees|equals|reconcil/i', $page, "{$key} shows its reconciliation");
        }
        $this->asOwner()->get(route('reports.balance-sheet', ['to' => '2026-11-30']))->assertSee('129,280.00');
        $this->asOwner()->get(route('reports.profit-loss', ['from' => '2026-11-01', 'to' => '2026-11-30']))->assertSee('15,280.00');
    }

    public function test_on_the_cash_basis_the_profit_and_loss_says_so_and_recognises_only_what_was_paid(): void
    {
        $pl = $this->report('profit-loss', ['from' => '2026-11-01', 'to' => '2026-11-30', 'basis' => 'cash']);
        $this->assertStringContainsStringIgnoringCase('cash', (string) $pl->header['basis']);
        $accrual = $this->report('profit-loss', ['from' => '2026-11-01', 'to' => '2026-11-30']);
        $this->assertNotSame($accrual->cell('Net profit', 'total'), $pl->cell('Net profit', 'total'), 'unpaid invoices and bills are not recognised on the cash basis');
        $this->assertSame(2_249_929, $pl->cell('Total income', 'total'), 'cash-basis income: the paid share of each invoice plus interest');
        $this->assertReportChecksPass($pl);
    }

    public function test_a_ledger_corrupted_on_purpose_is_reported_not_smoothed_over(): void
    {
        $this->liftJournalGuards();
        $entry = JournalEntry::query()->forContext($this->ledger)->where('source_type', 'invoice')->firstOrFail();
        DB::table('journal_lines')->where('journal_entry_id', $entry->id)->where('credit_cents', '>', 0)->limit(1)->update(['credit_cents' => DB::raw('credit_cents + 1')]);

        $tb = $this->report('trial-balance', ['to' => '2026-11-30']);
        $this->assertNotSame([], $tb->failedChecks(), 'the trial balance reports the one-cent difference');
        $this->asOwner()->get(route('reports.trial-balance', ['to' => '2026-11-30']))->assertOk();
        $verify = app(\App\Services\Accounting\GlVerifier::class)->verify($this->ledger);
        $this->assertFalse($verify['ok'], 'finance:gl:verify fails');
        $this->artisan('finance:gl:verify')->assertExitCode(1);
    }
}
