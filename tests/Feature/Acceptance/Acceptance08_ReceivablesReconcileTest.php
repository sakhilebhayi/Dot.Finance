<?php

namespace Tests\Feature\Acceptance;

use App\Models\Sales\SalesDocument;
use App\Models\Shared\Party;
use App\Models\Shared\Payment;
use App\Services\Sales\ReceiptService;
use App\Services\Sales\ReceivablesLedger;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;

/**
 * Owner criterion 8: AR reports reconcile. The ageing report, the Sales receivables page, the customer ledgers and the general
 * ledger's receivables control account say the same number in total and customer by customer, with buckets that match hand figures.
 *
 * Today is 30 Nov 2026, terms are 30 days. By hand:
 *   Acme  INV a 10 Nov  1,150.00 due 10 Dec  not yet due        Beta  INV d 20 Sep 1,150.00 due 20 Oct  41 days: 31 to 60
 *         INV b 15 Oct  2,300.00 due 14 Nov  16 days: 1 to 30         INV e 20 Aug 1,150.00 due 19 Sep  72 days: 61 to 90 (150.00 paid: 1,000.00 open)
 *         INV c  1 May    575.00 due 31 May  183 days: over 90        Beta also paid 300.00 on account, not yet applied
 *   Acme owes 4,025.00; Beta owes 2,150.00 less 300.00 on account; the book total is 5,875.00.
 */
class Acceptance08_ReceivablesReconcileTest extends AcceptanceCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIntegration();
        $line = fn (string $net) => [['description' => 'Services', 'quantity' => '1', 'unit_cents' => (int) $net, 'tax_code' => 'ZA_VAT_STD']];
        $this->issueInvoice($this->acme, $line('100000'), '2026-11-10');
        $this->issueInvoice($this->acme, $line('200000'), '2026-10-15');
        $this->issueInvoice($this->acme, $line('50000'), '2026-05-01');
        $this->issueInvoice($this->beta, $line('100000'), '2026-09-20');
        $e = $this->issueInvoice($this->beta, $line('100000'), '2026-08-20');
        $this->receive($this->beta, 15_000, '2026-10-05', $e);
        // 300.00 received from Beta that is not applied to any invoice
        Auth::login($this->owner);
        LedgerContext::run($this->ledger, fn () => app(ReceiptService::class)->receive($this->ledger, $this->owner, [
            'party_id' => $this->beta->id, 'cash_account_id' => $this->bank->id, 'amount_cents' => 30_000, 'paid_on' => '2026-11-20', 'method' => 'eft', 'idempotency_key' => $this->idem('onacct'), 'allocations' => [],
        ]));
    }

    public function test_the_ageing_report_puts_each_invoice_in_the_right_bucket_per_customer_and_ties_to_the_control_account(): void
    {
        $r = $this->report('ageing-receivables', ['to' => '2026-11-30']);

        $this->assertSame(['current' => 115_000, 'b30' => 230_000, 'b60' => 0, 'b90' => 0, 'over' => 57_500, 'total' => 402_500], array_intersect_key($r->row('Acme Retail')['cells'], array_flip(['current', 'b30', 'b60', 'b90', 'over', 'total'])));
        $beta = $r->row('Beta Stores')['cells'];
        $this->assertSame([115_000, 100_000, 0], [$beta['b60'], $beta['b90'], $beta['over']], 'Beta: one invoice 31-60 days, one 61-90 days net of its part payment');
        $this->assertSame(-30_000, $beta['current'] + $beta['b30'], 'the 300.00 received on account is shown as a negative item, not hidden');
        $this->assertSame(185_000, $beta['total']);
        $this->assertSame(587_500, $r->cell('Total', 'total'), 'the book total, by hand: 4,025.00 + 2,150.00 - 300.00');
        $this->assertReportChecksPass($r);
        $this->assertSame(587_500, $this->balance('ar'), 'and that is the receivables control account');
    }

    public function test_the_sales_receivables_page_the_csv_and_the_customer_ledgers_agree_with_the_books(): void
    {
        $ledger = app(ReceivablesLedger::class);
        $rec = LedgerContext::run($this->ledger, fn () => $ledger->reconcile($this->ledger));
        $this->assertTrue($rec['ok'], 'documents equal the general ledger');
        $this->assertSame(587_500, $rec['ledger_cents']);
        $this->assertSame(0, $rec['unattributed_cents'], 'every receivable posting names its customer');
        foreach ($rec['parties'] as $party) {
            $this->assertSame(0, $party['difference_cents'], $party['name'].' agrees with the books');
        }
        $byName = collect($rec['parties'])->keyBy('name');
        $this->assertSame(402_500, $byName['Acme Retail']['ledger_cents']);
        $this->assertSame(215_000 - 30_000, $byName['Beta Stores']['ledger_cents']);

        $page = $this->asOwner()->get(route('sales.receivables.index', ['as_of' => '2026-11-30']))->assertOk()->getContent();
        $this->assertStringContainsString('5,875.00', $page);
        $this->assertStringContainsString('Agrees with the books', $page);

        $rows = $this->csvRows($this->asOwner()->get(route('sales.receivables.csv'))->assertOk()->getContent());
        $this->assertSame('Receivables ageing', $rows[0][0]);
        $total = collect($rows)->first(fn ($r) => $r[0] === 'Total');
        $this->assertSame('5875.00', end($total), 'the CSV total is the book total');
    }

    /** Defect D-1601: a receipt dated before the account opened was journalled but not counted in the account, so the books stopped tying out. */
    public function test_a_receipt_dated_before_the_bank_account_opened_is_refused_and_the_books_still_tie_out(): void
    {
        $doc = SalesDocument::query()->forContext($this->ledger)->where('number', 'INV-00005')->firstOrFail();
        $this->asOwner()->post(route('sales.payments.store'), [
            'party_id' => $this->beta->id, 'cash_account_id' => $this->bank->id, 'amount' => '100.00', 'paid_on' => '2026-09-01', 'idempotency_key' => 'pre-open', 'allocations' => [$doc->id => '100.00'],
        ])->assertSessionHasErrors('sales');
        $this->assertSame(0, Payment::query()->forContext($this->ledger)->where('idempotency_key', 'pre-open')->count());
        $this->assertBooksVerify();
    }

    public function test_a_credit_note_and_a_part_payment_move_the_ageing_and_the_books_together(): void
    {
        $doc = SalesDocument::query()->forContext($this->ledger)->where('number', 'INV-00002')->firstOrFail(); // Acme's October invoice, 2,300.00
        $this->receive($this->acme, 100_000, '2026-11-25', $doc);
        $this->issueCreditNote($doc, '2026-11-26', 50_000);   // 500.00 + 75.00 VAT credited

        $r = $this->report('ageing-receivables', ['to' => '2026-11-30']);
        $this->assertSame(230_000 - 100_000 - 57_500, $r->cell('Acme Retail', 'b30'));
        $this->assertSame(587_500 - 100_000 - 57_500, $r->cell('Total', 'total'));
        $this->assertReportChecksPass($r);
        $this->assertTrue(app(ReceivablesLedger::class)->reconcile($this->ledger)['ok']);
        $this->assertBooksVerify();
    }

    public function test_the_ageing_as_at_an_earlier_date_is_derived_from_the_books_and_still_ties(): void
    {
        $r = $this->report('ageing-receivables', ['to' => '2026-10-31']);
        // on 31 Oct: May invoice and the Oct invoice (issued 15 Oct) and Beta's two; 10 Nov invoice and the 20 Nov receipt do not exist yet
        $this->assertSame(57_500 + 230_000 + 115_000 + 100_000, $r->cell('Total', 'total'));
        $this->assertReportChecksPass($r);
    }

    public function test_every_ageing_figure_drills_down_to_journal_lines(): void
    {
        $r = $this->report('ageing-receivables', ['to' => '2026-11-30']);
        $drill = $r->row('Acme Retail')['drill']['total'];
        $page = $this->asOwner()->get(route('reports.drill', $drill))->assertOk()->getContent();
        $this->assertStringContainsString('INV-0000', $page, 'the receivables lines show the invoices they came from');
    }

    public function test_another_ledgers_customers_never_appear_in_the_ageing(): void
    {
        $other = $this->user();
        $company = $this->company($other, 'Other Trading');
        $this->assertSame(0, Party::query()->withoutGlobalScopes()->where('team_id', $company->id)->count());
        $this->asMember($other)->get(route('reports.ageing-receivables'))->assertOk()->assertDontSee('Acme Retail');
        $this->inCompany($other, $company)->get(route('sales.receivables.index'))->assertDontSee('Acme Retail')->assertDontSee('Beta Stores');
        $this->assertTrue(CarbonImmutable::parse('2026-11-30')->isSameDay(now()));
    }
}
