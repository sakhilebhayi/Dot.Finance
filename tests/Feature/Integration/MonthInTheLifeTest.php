<?php

namespace Tests\Feature\Integration;

use App\Models\Account;
use App\Models\Accounting\JournalLine;
use App\Models\Accounting\LedgerSetting;
use App\Services\Accounting\GlVerifier;
use App\Services\Accounting\Periods;
use App\Services\Accounting\TrialBalance;
use App\Services\Purchases\ApLedger;
use App\Services\Reporting\ReportResult;
use App\Services\Reporting\ReportRunner;
use App\Services\Sales\ReceivablesLedger;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RunsAMonth;
use Tests\TestCase;

/**
 * A month in the life of a small VAT-registered company, November 2026, on a fresh ledger, through the real services (see RunsAMonth).
 * Every figure is worked out by hand here, not read back from the code under test:
 *
 *   Sales     INV-00001 Acme   10,000.00 standard + 2,000.00 zero-rated + 500.00 exempt, VAT 1,500.00   = 14,000.00, R5,000.00 paid
 *             INV-00002 Beta   20,000.00 standard, VAT 3,000.00 = 23,000.00; credit note 2,000.00 + 300.00 = 2,300.00; the other
 *                              20,700.00 arrives on the bank statement and is settled from the statement line
 *   Purchases Paper Co bill    4,000.00 + 600.00 VAT = 4,600.00; credit note 400.00 + 60.00 = 460.00; the 4,140.00 left is paid
 *             Lunch Co bill      900.00 + 135.00 VAT blocked (entertainment) = 1,035.00, settled from the statement line
 *             Paper Co toner   2,000.00 + 300.00 VAT = 2,300.00, unpaid
 *             Fuel claim       1,000.00 + 150.00 VAT = 1,150.00, approved, paid back
 *   Payroll   PAYE 6,000.00, UIF 500.00 + 500.00, SDL 500.00 (the tax portion is journalled)
 *   Bank      opening 100,000.00; + 5,000.00 - 4,140.00 - 1,150.00 + 20,700.00 - 1,035.00 - 120.00 fee + 35.00 interest = 119,290.00
 *
 *   Income    12,500.00 + 18,000.00 + 35.00 interest                        = 30,535.00
 *   Expenses  3,600.00 + 2,000.00 + 1,035.00 + 1,000.00 + 120.00 + 6,500.00 + 500.00 + 500.00 = 15,255.00
 *   Profit    15,280.00     Receivables 9,000.00     Payables 2,300.00
 *   VAT       output 4,500.00 - 300.00 credit note = 4,200.00; input 600.00 - 60.00 + 150.00 + 300.00 = 990.00; payable 3,210.00
 */
class MonthInTheLifeTest extends TestCase
{
    use RefreshDatabase;
    use RunsAMonth;

    private function report(string $key, array $input = []): ReportResult
    {
        return app(ReportRunner::class)->run($this->ledger, $key, $input + ['from' => '2026-11-01', 'to' => '2026-11-30'], CarbonImmutable::parse('2026-11-30'));
    }

    private function assertChecksPass(ReportResult $r): void
    {
        $this->assertNotEmpty($r->checks, $r->key.' made no reconciliation check');
        $this->assertSame([], array_map(fn ($c) => $r->key.' / '.$c['label'].': '.$c['detail'], $r->failedChecks()));
    }

    public function test_every_journal_balances_and_the_trial_balance_is_zero(): void
    {
        $this->runMonth();

        $unbalanced = DB::table('journal_entries as e')->join('journal_lines as l', 'l.journal_entry_id', '=', 'e.id')->where('e.team_id', $this->company->id)
            ->groupBy('e.id')->havingRaw('SUM(l.debit_cents) <> SUM(l.credit_cents)')->pluck('e.id');
        $this->assertCount(0, $unbalanced, 'unbalanced entries: '.$unbalanced->implode(','));
        $this->assertGreaterThan(15, DB::table('journal_entries')->where('team_id', $this->company->id)->count());

        $tb = app(TrialBalance::class)->asOf($this->ledger, '2026-11-30');
        $this->assertTrue($tb['balanced']);
        $this->assertSame($tb['total_debits'], $tb['total_credits']);
        $this->assertSame(14_453_500, $tb['total_debits']);
        $this->assertTrue($tb['equation']['holds'], 'assets = liabilities + equity + income - expenses');
    }

    public function test_the_gl_verifier_and_the_command_pass(): void
    {
        $this->runMonth();

        $this->assertBooksVerify();
        $verify = app(GlVerifier::class)->verify($this->ledger);
        $this->assertContains('cash_tie_out', collect($verify['checks'])->pluck('code')->all(), 'the cash ledger tie-out is part of the verification');

        $this->artisan('finance:gl:verify')->assertExitCode(0);
    }

    public function test_receivables_and_payables_agree_with_the_ledger_in_total_and_party_by_party(): void
    {
        $this->runMonth();
        $ctx = $this->ledger;

        $ar = app(ReceivablesLedger::class);
        $this->assertTrue($ar->reconcile($ctx)['ok']);
        $this->assertSame(900_000, $this->balance('ar'));
        $this->assertSame(900_000, $ar->glBalance($ctx));
        $this->assertSame(['Acme Retail' => 900_000], collect($ar->ageing($ctx, 'due', CarbonImmutable::parse('2026-11-30'))['rows'])->mapWithKeys(fn ($r) => [$r['party']->name => $r['total']])->all());
        $this->assertSame(0, $ar->customerBalanceBase($ctx, $this->beta->id), 'Beta paid 20,700.00 after a 2,300.00 credit note');

        $ap = app(ApLedger::class)->reconcile($ctx);
        $this->assertTrue($ap['ok']);
        $this->assertSame(230_000, -$this->balance('ap'));
        $this->assertSame(230_000, app(ApLedger::class)->ageing($ctx, CarbonImmutable::parse('2026-11-30'))['grand_total']);

        // the reports say the same
        $this->assertChecksPass($this->report('ageing-receivables'));
        $this->assertChecksPass($this->report('ageing-payables'));
        $this->assertSame(900_000, $this->report('ageing-receivables')->cell('Total', 'total'));
        $this->assertSame(230_000, $this->report('ageing-payables')->cell('Total', 'total'));
    }

    public function test_the_statements_agree_with_the_books_and_with_each_other(): void
    {
        $this->runMonth();

        $pl = $this->report('profit-loss');
        $this->assertSame(3_053_500, $pl->cell('Total income', 'total'));
        $this->assertSame(1_525_500, $pl->cell('Total expenses', 'total'));
        $this->assertSame(1_528_000, $pl->cell('Net profit', 'total'));
        $this->assertChecksPass($pl);
        $this->assertNull($pl->row('6500 Bank charges'), 'the bank charge posts to the chart\'s own Bank charges account, not a second one');
        $this->assertSame(12_000, $pl->cell('6200 Bank charges', 'total'));

        $bs = $this->report('balance-sheet');
        $this->assertSame(12_928_000, $bs->cell('Total assets', 'total'));
        $this->assertSame(1_400_000, $bs->cell('Total liabilities', 'total'));
        $this->assertSame(11_528_000, $bs->cell('Total equity', 'total'));
        $this->assertSame($bs->cell('Total assets', 'total'), $bs->cell('Total liabilities and equity', 'total'));
        $this->assertSame(1_528_000, $bs->cell('Current year earnings (calculated)', 'total'), 'the balance sheet\'s earnings are the P&L\'s profit');
        $this->assertChecksPass($bs);

        $cf = $this->report('cash-flow-statement');
        $this->assertSame(10_000_000, $cf->cell('Opening cash and bank balances', 'total'));
        $this->assertSame(11_929_000, $cf->cell('Closing cash and bank balances', 'total'));
        $this->assertSame(1_929_000, $cf->cell('Net change in cash', 'total'));
        $this->assertChecksPass($cf);

        $tb = $this->report('trial-balance');
        $this->assertSame(14_453_500, $tb->cell('Total', 'debit'));
        $this->assertSame(14_453_500, $tb->cell('Total', 'credit'));
        $this->assertChecksPass($tb);

        // cash flow ties to the bank account the cash ledger keeps, to the statement and to the reconciliation
        $balance = Account::query()->forContext($this->ledger)->withBalance()->findOrFail($this->bank->id)->balanceCents();
        $this->assertSame(11_929_000, $balance);
        $this->assertSame(11_929_000, $cf->cell('Closing balance: 1011 Current account', 'total'));
        $this->assertSame('completed', $this->session->status);
        $this->assertSame(0, $this->session->statement_closing_cents - $balance);
        $this->assertSame(11_929_000, (int) DB::table('bank_statements')->where('team_id', $this->company->id)->value('closing_balance_cents'));
        $this->assertSame(0, DB::table('bank_statement_lines')->where('team_id', $this->company->id)->whereNull('reconciliation_id')->count(), 'every statement line was reconciled');
    }

    public function test_the_payments_are_the_ones_the_bank_module_settled(): void
    {
        $this->runMonth();

        // Beta's invoice was settled by the Sales payment service from a statement line, Lunch Co's bill by the Purchases one
        $this->assertSame('paid', $this->inv2->fresh()->status);
        $this->assertSame('paid', $this->bill2->fresh()->status);
        $this->assertSame('partially_paid', $this->inv1->fresh()->status);
        $this->assertSame('open', $this->bill3->fresh()->status);

        $settled = DB::table('payments')->where('team_id', $this->company->id)->where('notes', 'like', 'Settled from bank statement line%')->get();
        $this->assertCount(2, $settled);
        $this->assertSame(['in', 'out'], $settled->pluck('direction')->sort()->values()->all());

        // each payment has exactly one cash-ledger row, marked as a settlement row, and the bank matched it
        foreach (DB::table('payments')->where('team_id', $this->company->id)->get() as $payment) {
            $row = DB::table('transactions')->where('id', $payment->transaction_id)->first();
            $this->assertNotNull($row, 'payment '.$payment->id.' has no cash-ledger row');
            $this->assertSame('payment:'.$payment->id, $row->settlement_ref);
            $this->assertNotNull($row->bank_match_id, 'payment '.$payment->id.' was not matched to a statement line');
        }
        $this->assertSame(5, DB::table('transactions')->where('team_id', $this->company->id)->whereNotNull('settlement_ref')->count());
    }

    public function test_closing_the_period_locks_it_and_changes_no_figure(): void
    {
        $this->runMonth();
        $before = $this->report('trial-balance')->rows;

        LedgerContext::run($this->ledger, fn () => app(Periods::class)->closeThrough($this->ledger, CarbonImmutable::parse('2026-11-30'), $this->owner));

        $this->assertSame($before, $this->report('trial-balance')->rows);
        $this->assertBooksVerify();
        $this->assertSame('2026-11-30', LedgerContext::run($this->ledger, fn () => LedgerSetting::query()->forContext($this->ledger)->value('books_closed_through'))?->toDateString());

        // a late invoice dated inside the closed period is refused and nothing is written
        $journalLines = JournalLine::query()->forContext($this->ledger)->count();
        try {
            $this->issueInvoice($this->acme, [['description' => 'Late', 'quantity' => '1', 'unit_cents' => 10_000, 'tax_code' => 'ZA_VAT_STD']], '2026-11-20');
            $this->fail('the period is closed');
        } catch (\Throwable $e) {
            $this->assertMatchesRegularExpression('/clos/i', $e->getMessage());
        }
        $this->assertSame($journalLines, JournalLine::query()->forContext($this->ledger)->count());
    }
}
