<?php

namespace Tests\Feature\Integration;

use App\Models\Accounting\JournalEntry;
use App\Models\Sales\SalesDocument;
use App\Services\Purchases\SupplierPayments;
use App\Services\Reporting\CashBasis;
use App\Services\Reporting\ReportRunner;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RunsAMonth;
use Tests\TestCase;

/**
 * Reporting's cash basis names the documents it recognises as they are paid by their journal source types and by how a payment
 * allocation names them. Those names are configuration; this proves they are the names Sales and Purchases really use, and that the
 * cash-basis profit and loss comes out right for the same hand-worked month as MonthInTheLifeTest:
 *
 *   Income    INV-1: 12,500.00 x 5,000.00 / 14,000.00 paid = 4,464.29 (the three lines 3,571.43 + 714.29 + 178.57 rounded line by line = 4,464.29)
 *             INV-2: 20,000.00 - 2,000.00 credit note, settled in full  = 18,000.00     interest 35.00     total 22,499.29
 *   Expenses  Paper Co 3,600.00 (4,000.00 less the 400.00 credit, paid in full) + Lunch Co 1,035.00 + the fuel claim 1,000.00
 *             + bank fee 120.00 + payroll 7,500.00 (not documents: as posted)                             total 13,255.00
 *             Toner (2,000.00) is unpaid: nothing yet
 *   Profit    22,499.29 - 13,255.00 = 9,244.29
 */
class CashBasisWiringTest extends TestCase
{
    use RefreshDatabase;
    use RunsAMonth;

    public function test_the_configured_source_and_allocation_names_are_the_ones_the_modules_use(): void
    {
        $this->runMonth();
        $cash = app(CashBasis::class);

        // journal source types: what Sales and Purchases post as invoices and bills (credit notes are separate or carry no control debit)
        $sources = JournalEntry::query()->forContext($this->ledger)->distinct()->pluck('source_type')->all();
        foreach ($cash->documentTypes() as $type) {
            $this->assertContains($type, $sources, "no module posts a '{$type}' entry, so the cash basis would recognise nothing");
        }
        $this->assertSame('invoice', JournalEntry::query()->forContext($this->ledger)->findOrFail($this->inv1->gl_entry_id)->source_type);
        $this->assertSame('credit_note', JournalEntry::query()->forContext($this->ledger)->findOrFail($this->creditNote->gl_entry_id)->source_type);
        $this->assertSame('bill', JournalEntry::query()->forContext($this->ledger)->findOrFail($this->bill1->posted_entry_id)->source_type);
        $this->assertSame('bill', JournalEntry::query()->forContext($this->ledger)->findOrFail($this->claim->posted_entry_id)->source_type, 'a claim is posted as a bill entry, source id claim-<id>');

        // allocation type names: what the payment allocations store for each document
        $stored = DB::table('payment_allocations')->where('team_id', $this->company->id)->distinct()->pluck('allocatable_type')->all();
        $this->assertNotEmpty($stored);
        foreach ($stored as $type) {
            $this->assertNotNull($cash->documentTypeFor($type), "'{$type}' is a document type payments are allocated to, but the cash basis does not know it");
        }
        $this->assertSame('invoice', $cash->documentTypeFor((new SalesDocument)->getMorphClass()));
        $this->assertSame('bill', $cash->documentTypeFor('bill'));
        $this->assertSame('bill', $cash->documentTypeFor('expense_claim'));
    }

    public function test_the_cash_basis_profit_and_loss_is_right_for_the_month(): void
    {
        $this->runMonth();

        $cashPl = app(ReportRunner::class)->run($this->ledger, 'profit-loss', ['from' => '2026-11-01', 'to' => '2026-11-30', 'basis' => 'cash'], CarbonImmutable::parse('2026-11-30'));

        $this->assertSame([], array_map(fn ($c) => $c['label'].': '.$c['detail'], $cashPl->failedChecks()));
        $this->assertSame(2_249_929, $cashPl->cell('Total income', 'total'));
        $this->assertSame(1_325_500, $cashPl->cell('Total expenses', 'total'));
        $this->assertSame(924_429, $cashPl->cell('Net profit', 'total'));
        $this->assertSame(360_000, $cashPl->cell('6190 Stationery and office costs', 'total') - 103_500, 'Paper Co 3,600.00 plus Lunch Co 1,035.00 (the denied VAT is cost); the toner is unpaid');
        $this->assertSame(100_000, $cashPl->cell('6170 Motor vehicle and fuel', 'total'), 'the paid-back claim');
    }

    public function test_everything_paid_makes_cash_and_accrual_agree(): void
    {
        $this->runMonth();
        LedgerContext::run($this->ledger, function () {
            // pay the toner and the rest of Acme's invoice: now every document is settled and the two bases must agree
            app(SupplierPayments::class)->pay($this->ledger, $this->owner, [
                'party_id' => $this->paperCo->id, 'cash_account_id' => $this->bank->id, 'paid_on' => '2026-11-27', 'method' => 'eft', 'idempotency_key' => $this->idem('pay'),
                'allocations' => [['type' => 'bill', 'id' => $this->bill3->id, 'amount_cents' => 230_000]],
            ]);
        });
        $this->receive($this->acme, 900_000, '2026-11-28', $this->inv1, 'ACME FINAL');

        $run = fn (string $basis) => app(ReportRunner::class)->run($this->ledger, 'profit-loss', ['from' => '2026-11-01', 'to' => '2026-11-30', 'basis' => $basis], CarbonImmutable::parse('2026-11-30'));
        $accrual = $run('accrual');
        $cash = $run('cash');

        $this->assertSame(1_528_000, $accrual->cell('Net profit', 'total'));
        $this->assertSame(1_528_000, $cash->cell('Net profit', 'total'));
        $this->assertSame($accrual->cell('Total income', 'total'), $cash->cell('Total income', 'total'));
        $this->assertSame($accrual->cell('Total expenses', 'total'), $cash->cell('Total expenses', 'total'));
    }
}
