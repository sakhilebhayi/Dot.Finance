<?php

namespace Tests\Feature\Acceptance;

use App\Models\Accounting\JournalLine;
use App\Models\Purchases\Bill;
use App\Services\Purchases\ApLedger;
use App\Services\Purchases\SupplierStatement;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/**
 * Owner criterion 9: AP reports reconcile. The payables ageing, the Purchases AP page, the supplier statements and the general
 * ledger's payables control account agree in total and supplier by supplier, with buckets that match hand figures.
 *
 * Today is 30 Nov 2026, terms 30 days. By hand:
 *   Paper Co  bill a 10 Nov 1,150.00 due 10 Dec not yet due        Lunch Co  bill c 15 Sep 1,150.00 due 15 Oct  46 days: 31 to 60
 *             bill b 10 Oct 2,300.00 due  9 Nov 21 days: 1 to 30             bill d  1 Aug   575.00 due 31 Aug  91 days: over 90
 *             500.00 paid on bill b: 1,800.00 open
 *   Paper Co is owed 2,950.00, Lunch Co 1,725.00; the book total is 4,675.00.
 */
class Acceptance09_PayablesReconcileTest extends AcceptanceCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIntegration();
        $line = fn (int $net) => $this->billLine(['unit_price_cents' => $net]);
        $this->postBill($this->paperCo, '2026-11-10', [$line(100_000)], ['supplier_reference' => 'PA-1']);
        $b = $this->postBill($this->paperCo, '2026-10-10', [$line(200_000)], ['supplier_reference' => 'PA-2']);
        $this->postBill($this->lunchCo, '2026-09-15', [$line(100_000)], ['supplier_reference' => 'LU-1']);
        $this->postBill($this->lunchCo, '2026-08-01', [$line(50_000)], ['supplier_reference' => 'LU-2']);

        $this->asOwner()->post(route('purchases.payments.store'), [
            'party_id' => $this->paperCo->id, 'cash_account_id' => $this->bank->id, 'paid_on' => '2026-11-15', 'idempotency_key' => 'a09-pay', 'pay' => ['bill-'.$b->id => '500.00'],
        ])->assertSessionHasNoErrors();
    }

    public function test_the_ageing_report_puts_each_bill_in_the_right_bucket_per_supplier_and_ties_to_the_payables_account(): void
    {
        $r = $this->report('ageing-payables', ['to' => '2026-11-30']);

        $paper = $r->row('Paper Co')['cells'];
        $this->assertSame([115_000, 180_000, 0, 0, 0, 295_000], [$paper['current'], $paper['b30'], $paper['b60'], $paper['b90'], $paper['over'], $paper['total']]);
        $lunch = $r->row('Lunch Co')['cells'];
        $this->assertSame([0, 0, 115_000, 0, 57_500, 172_500], [$lunch['current'], $lunch['b30'], $lunch['b60'], $lunch['b90'], $lunch['over'], $lunch['total']]);
        $this->assertSame(467_500, $r->cell('Total', 'total'));
        $this->assertReportChecksPass($r);
        $this->assertSame(467_500, -$this->balance('ap'), 'that is the payables control account');
    }

    public function test_the_ap_ledger_reconciles_supplier_by_supplier_and_the_page_and_csv_say_so(): void
    {
        $rec = app(ApLedger::class)->reconcile($this->ledger);
        $this->assertTrue($rec['ok']);
        $this->assertSame(0, $rec['unassigned_books_cents']);
        $this->assertSame([295_000, 172_500], collect($rec['suppliers'])->sortBy('party')->pluck('books_cents')->sortDesc()->values()->all());
        foreach ($rec['suppliers'] as $supplier) {
            $this->assertSame(0, $supplier['difference_cents'], $supplier['party'].' agrees with the books');
        }

        $page = $this->asOwner()->get(route('purchases.ap.index', ['as_at' => '2026-11-30']))->assertOk()->getContent();
        $this->assertStringContainsString('4,675.00', $page);

        $csv = $this->asOwner()->get(route('purchases.ap.index', ['as_at' => '2026-11-30', 'format' => 'csv']));
        $csv->assertOk();
        $rows = $this->csvRows($csv->streamedContent());
        $total = collect($rows)->first(fn ($r) => $r[0] === 'Total');
        $this->assertSame('4675.00', end($total));
        $books = collect($rows)->first(fn ($r) => str_starts_with($r[0], 'The books'));
        $this->assertSame('Agrees', end($books));
    }

    public function test_a_supplier_statement_closes_at_what_the_ledger_says_we_owe_that_supplier(): void
    {
        $statement = LedgerContext::run($this->ledger, fn () => app(SupplierStatement::class)->forSupplier($this->paperCo, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-11-30')));
        $this->assertSame(295_000, $statement['currencies']['ZAR']['closing']);
        $this->assertSame(295_000, $this->supplierBooks($this->paperCo->id));
        $this->asOwner()->get(route('purchases.suppliers.statement', $this->paperCo))->assertOk()->assertSee('2,950.00');
    }

    public function test_a_supplier_credit_note_and_a_voided_bill_move_the_ageing_and_the_books_together(): void
    {
        $bill = Bill::query()->forContext($this->ledger)->where('supplier_reference', 'LU-1')->firstOrFail();
        $this->asOwner()->post(route('purchases.bills.void', $bill), ['reason' => 'Billed twice'])->assertSessionHasNoErrors();

        $r = $this->report('ageing-payables', ['to' => '2026-11-30']);
        $this->assertSame(467_500 - 115_000, $r->cell('Total', 'total'));
        $this->assertSame(0, (int) $r->cell('Lunch Co', 'b60'), 'Lunch Co no longer has a 31-60 day amount');
        $this->assertReportChecksPass($r);
        $this->assertTrue(app(ApLedger::class)->reconcile($this->ledger)['ok']);
        $this->assertBooksVerify();
    }

    public function test_the_ageing_as_at_an_earlier_date_still_ties_and_a_stranger_sees_none_of_it(): void
    {
        $r = $this->report('ageing-payables', ['to' => '2026-10-31']);
        // 31 Oct: Paper Co bill b (2,300.00, nothing paid yet) and Lunch Co's two bills; the 10 Nov bill does not exist yet
        $this->assertSame(230_000 + 115_000 + 57_500, $r->cell('Total', 'total'));
        $this->assertReportChecksPass($r);

        $other = $this->user();
        $company = $this->company($other, 'Other Trading');
        $this->inCompany($other, $company)->get(route('reports.ageing-payables'))->assertOk()->assertDontSee('Paper Co')->assertDontSee('Lunch Co');
        $this->inCompany($other, $company)->get(route('purchases.ap.index'))->assertOk()->assertDontSee('Paper Co');
    }

    private function supplierBooks(int $partyId): int
    {
        return (int) JournalLine::query()->forContext($this->ledger)->where('gl_account_id', $this->gl('ap')->id)->where('party_id', $partyId)
            ->selectRaw('COALESCE(SUM(credit_cents),0) - COALESCE(SUM(debit_cents),0) as n')->value('n');
    }
}
