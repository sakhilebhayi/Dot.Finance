<?php

namespace Tests\Feature\Sales;

use App\Contracts\Intelligence\ReceivablesSource;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\PostingLine;
use App\Services\Accounting\PostingRequest;
use App\Services\Intelligence\FinanceAssistant;
use App\Services\Sales\ReceiptService;
use App\Services\Sales\ReceivablesLedger;
use App\Services\Shared\PaymentAllocator;
use App\Services\Shared\PaymentRecorder;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSales;
use Tests\Support\StubInvoice;
use Tests\TestCase;

/**
 * The outside jury found four different "owed to you" figures on one login (Invoices 110,797.60, Receivables
 * "does not agree" by 859,050.00, the general ledger 429,285.60, and an Assistant claiming the ledger as its source).
 * Root causes, each pinned here:
 *   1. a payment fully allocated to something that is not a sales document was counted again as customer credit;
 *   2. the pages headlined different slices of one calculation, and the Assistant named a source it did not use;
 *   3. invoices posted straight to the journal (no sales document) opened a real gap that was only described in a sentence.
 */
class OwedToYouOneFigureTest extends TestCase
{
    use BuildsSales;
    use RefreshDatabase;

    private function receivables(): ReceivablesLedger
    {
        return app(ReceivablesLedger::class);
    }

    /** An invoice that exists only in the journal (the way the reporting demo once posted them), paid by a recorded payment. */
    private function journalOnlyInvoice(int $gross, bool $paid): void
    {
        LedgerContext::run($this->ledger, function () use ($gross, $paid) {
            $net = intdiv($gross * 100, 115);
            app(JournalPoster::class)->post(new PostingRequest(CarbonImmutable::today()->subDays(40), 'invoice', 9001, 1, 'Invoice INV-9001 Old customer', [
                new PostingLine('ar', debitCents: $gross, partyId: $this->customer->id),
                new PostingLine('sales', creditCents: $net, partyId: $this->customer->id),
                new PostingLine('vat_output', creditCents: $gross - $net, partyId: $this->customer->id),
            ]));
            if ($paid) {
                app(JournalPoster::class)->post(new PostingRequest(CarbonImmutable::today()->subDays(10), 'payment', 'rcpt-9001', 1, 'Receipt for INV-9001', [
                    new PostingLine('bank', debitCents: $gross, partyId: $this->customer->id),
                    new PostingLine('ar', creditCents: $gross, partyId: $this->customer->id),
                ]));
                $payment = app(PaymentRecorder::class)->record([
                    'direction' => 'in', 'party_id' => $this->customer->id, 'cash_account_id' => $this->bank->id, 'amount_cents' => $gross,
                    'paid_on' => CarbonImmutable::today()->subDays(10)->toDateString(), 'idempotency_key' => 'legacy-rcpt-9001',
                ], $this->owner, $this->ledger);
                app(PaymentAllocator::class)->allocate($payment, new StubInvoice($gross, 'ZAR', $this->customer->id, 'in', $this->ledger->ownerAttributes()), $gross, by: $this->owner);
            }
        });
    }

    public function test_a_payment_fully_allocated_to_a_non_sales_invoice_is_not_customer_credit(): void
    {
        $this->setUpSales();
        $this->journalOnlyInvoice(115_000, paid: true);

        $credits = LedgerContext::run($this->ledger, fn () => $this->receivables()->unappliedCredits($this->ledger));

        $this->assertCount(0, $credits, 'the whole payment was applied; nothing is left on account');
        $this->assertSame(0, LedgerContext::run($this->ledger, fn () => $this->receivables()->customerBalanceBase($this->ledger, $this->customer->id)));
    }

    public function test_a_part_allocated_foreign_style_payment_keeps_only_the_unallocated_share(): void
    {
        $this->setUpSales();
        LedgerContext::run($this->ledger, function () {
            $payment = app(PaymentRecorder::class)->record([
                'direction' => 'in', 'party_id' => $this->customer->id, 'cash_account_id' => $this->bank->id, 'amount_cents' => 100_000,
                'paid_on' => CarbonImmutable::today()->toDateString(), 'idempotency_key' => 'half',
            ], $this->owner, $this->ledger);
            app(PaymentAllocator::class)->allocate($payment, new StubInvoice(60_000, 'ZAR', $this->customer->id, 'in', $this->ledger->ownerAttributes()), 60_000, by: $this->owner);
        });

        $credits = LedgerContext::run($this->ledger, fn () => $this->receivables()->unappliedCredits($this->ledger));

        $this->assertCount(1, $credits);
        $this->assertSame(40_000, $credits->first()['cents']);
        $this->assertSame(40_000, $credits->first()['base_cents'], 'the base figure follows the amount actually left');
    }

    public function test_a_gap_is_real_and_every_cent_of_it_is_listed_as_a_reconciling_item(): void
    {
        $this->setUpSales();
        $this->issued([$this->line('Work', '1', 100_000)]);   // 115,000 owed, in Sales and in the books
        $this->journalOnlyInvoice(230_000, paid: false);       // 230,000 in the books only

        $rec = LedgerContext::run($this->ledger, fn () => $this->receivables()->reconcile($this->ledger));

        $this->assertFalse($rec['ok']);
        $this->assertSame(115_000, $rec['documents_cents']);
        $this->assertSame(345_000, $rec['ledger_cents']);
        $this->assertSame(230_000, $rec['difference_cents']);
        $this->assertCount(1, $rec['items'], 'only the journal-only invoice; the Sales invoice is accounted for by its document');
        $this->assertSame('invoice', $rec['items'][0]['source']);
        $this->assertSame(230_000, $rec['items'][0]['cents']);
        $this->assertSame(230_000, $rec['items_cents']);
        $this->assertSame(0, $rec['remainder_cents'], 'the listed items explain the whole difference');
    }

    public function test_no_gap_means_no_items(): void
    {
        $this->setUpSales();
        $this->issued();

        $rec = LedgerContext::run($this->ledger, fn () => $this->receivables()->reconcile($this->ledger));

        $this->assertTrue($rec['ok']);
        $this->assertSame([], $rec['items']);
        $this->assertSame(0, $rec['remainder_cents']);
    }

    public function test_sales_payments_credit_notes_and_write_offs_are_never_reconciling_items(): void
    {
        $this->setUpSales();
        $invoice = $this->issued([$this->line('Work', '1', 100_000)]);
        $this->receiveAll($invoice, 50_000);

        $rec = LedgerContext::run($this->ledger, fn () => $this->receivables()->reconcile($this->ledger));

        $this->assertTrue($rec['ok']);
        $this->assertSame([], $rec['items']);
    }

    public function test_every_surface_derives_owed_to_you_from_the_one_summary(): void
    {
        $this->setUpSales();
        $this->issued([$this->line('Work', '1', 100_000)]);
        $this->journalOnlyInvoice(230_000, paid: true);   // a settled journal-only invoice: no effect on what is owed

        $ctx = $this->ledger;
        $summary = LedgerContext::run($ctx, fn () => $this->receivables()->summary($ctx));
        $ageing = LedgerContext::run($ctx, fn () => $this->receivables()->ageing($ctx));
        $position = LedgerContext::run($ctx, fn () => app(ReceivablesSource::class)->position($ctx));

        $this->assertSame(115_000, $summary['net_base_cents']);
        $this->assertSame($summary['net_base_cents'], $ageing['totals']['total'], 'the Receivables and Customers totals');
        $this->assertSame($summary['net_base_cents'], $position['total_cents'], 'the Assistant');
        $this->assertSame($summary['net_base_cents'], $summary['reconcile']['documents_cents'], 'the proof compares the same figure');

        $this->actingAs($this->owner)->get(route('sales.invoices.index'))->assertOk()->assertSee('Owed to you')->assertSee('1,150.00');
    }

    public function test_the_assistant_names_the_real_source_and_shows_the_gap_beside_the_figure(): void
    {
        $this->setUpSales();
        $this->issued([$this->line('Work', '1', 100_000)]);
        $this->journalOnlyInvoice(230_000, paid: false);

        $answer = app(FinanceAssistant::class)->ask($this->owner, $this->ledger, 'Who owes me money?');

        $this->assertSame('answered', $answer->status);
        $this->assertStringContainsString('ZAR 1,150.00', $answer->answer_text, 'the Sales figure, not the ledger account');
        $notes = implode(' ', $answer->answer['notes']);
        $this->assertStringContainsString('invoices, credit notes and payments recorded in Sales', $notes);
        $this->assertStringNotContainsString('Worked out from the receivables account of the general ledger', $notes, 'the claim that was false');
        $this->assertStringContainsString('ZAR 3,450.00', $notes, 'what the ledger account holds');
        $this->assertStringContainsString('ZAR 2,300.00', $notes, 'the gap');
    }

    public function test_the_receivables_page_lists_the_reconciling_items(): void
    {
        $this->setUpSales();
        $this->issued([$this->line('Work', '1', 100_000)]);
        $this->journalOnlyInvoice(230_000, paid: false);

        $this->actingAs($this->owner)->get(route('sales.receivables.index'))->assertOk()
            ->assertSee('Does not agree with the books')
            ->assertSee('What makes up the ZAR 2,300.00')
            ->assertSee('Invoices posted without a sales invoice')
            ->assertSee('INV-9001');

        $this->get(route('sales.invoices.index'))->assertOk()->assertSee('Books differ by ZAR 2,300.00');
    }

    private function receiveAll($invoice, int $cents): void
    {
        LedgerContext::run($this->ledger, fn () => app(ReceiptService::class)->receive($this->ledger, $this->owner, [
            'party_id' => $this->customer->id, 'cash_account_id' => $this->bank->id, 'amount_cents' => $cents, 'paid_on' => CarbonImmutable::today()->toDateString(),
            'reference' => 'T1', 'idempotency_key' => 'T1', 'allocations' => [['document_id' => $invoice->id, 'amount_cents' => $cents]],
        ]));
    }
}
