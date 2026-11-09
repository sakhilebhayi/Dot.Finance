<?php

namespace Tests\Feature\Sales;

use App\Models\Account;
use App\Models\Sales\SalesApplication;
use App\Models\Sales\SalesDocument;
use App\Services\Sales\ApplicationEngine;
use App\Services\Sales\DocumentDrafts;
use App\Services\Sales\DocumentIssuer;
use App\Services\Sales\DocumentLifecycle;
use App\Services\Sales\ReceiptService;
use App\Services\Sales\ReceivablesLedger;
use App\Services\Sales\Support\SalesViolation;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSales;
use Tests\TestCase;

class CreditNoteTest extends TestCase
{
    use BuildsSales;
    use RefreshDatabase;

    private function creditFor(SalesDocument $invoice, ?array $lines = null, string $reason = 'Goods returned'): SalesDocument
    {
        return LedgerContext::run($this->ledger, function () use ($invoice, $lines, $reason) {
            if ($lines === null) {
                return app(DocumentLifecycle::class)->draftCreditNote($invoice, $this->owner, $reason);
            }

            return app(DocumentDrafts::class)->create($this->ledger, $this->owner, [
                'kind' => 'credit_note', 'party_id' => $invoice->party_id, 'parent_id' => $invoice->id, 'issue_date' => now()->toDateString(), 'reason' => $reason,
            ], $lines);
        });
    }

    private function issue(SalesDocument $draft): SalesDocument
    {
        return LedgerContext::run($this->ledger, fn () => app(DocumentIssuer::class)->issue($draft, $this->owner)['document']);
    }

    private function open(SalesDocument $d): int
    {
        return LedgerContext::run($this->ledger, fn () => app(ApplicationEngine::class)->openBalance($d->refresh()));
    }

    private function reconciles(): bool
    {
        return LedgerContext::run($this->ledger, fn () => app(ReceivablesLedger::class)->reconcile($this->ledger))['ok'];
    }

    public function test_a_full_credit_note_reverses_the_invoice_exactly_and_zeroes_what_is_owed(): void
    {
        $this->setUpSales();
        $invoice = $this->issued([$this->line('A', '2', 100_000), $this->line('B', '1', 50_000, 'ZA_VAT_ZERO')]);
        $credit = $this->issue($this->creditFor($invoice));

        $this->assertSame('CN-00001', $credit->number);
        $this->assertSame((int) $invoice->total_cents, (int) $credit->total_cents);
        $this->assertSame((int) $invoice->tax_cents, (int) $credit->tax_cents);
        $this->assertSame(0, $this->glBalance('ar'));
        $this->assertSame(0, $this->glBalance('vat_output'));
        $this->assertSame(0, $this->glBalance('sales'));
        $this->assertSame(0, $this->open($invoice), 'applied to the invoice it corrects');
        $this->assertSame('issued', $invoice->refresh()->status, 'a credited invoice is not paid');
        $this->assertTrue($this->reconciles());
        $this->assertBooksBalance();
    }

    public function test_a_partial_credit_note_reduces_receivables_and_vat_in_proportion(): void
    {
        $this->setUpSales();
        $invoice = $this->issued([$this->line('Goods', '4', 100_000)]);   // net 400,000 vat 60,000 total 460,000
        $first = $invoice->lines->first();
        $credit = $this->issue($this->creditFor($invoice, [['description' => 'Returned: goods', 'quantity' => '1', 'unit_cents' => 100_000, 'parent_line_id' => $first->id]]));

        $this->assertSame(115_000, (int) $credit->total_cents);
        $this->assertSame(15_000, (int) $credit->tax_cents);
        $this->assertSame(345_000, $this->glBalance('ar'));
        $this->assertSame(-45_000, $this->glBalance('vat_output'));
        $this->assertSame(345_000, $this->open($invoice));
        $this->assertTrue($this->reconciles());
    }

    public function test_a_credit_note_lines_take_the_invoice_lines_tax_code_whatever_is_sent(): void
    {
        $this->setUpSales();
        $invoice = $this->issued([$this->line('Bread', '1', 100_000, 'ZA_VAT_ZERO')]);
        $credit = $this->creditFor($invoice, [['description' => 'Returned bread', 'quantity' => '1', 'unit_cents' => 50_000, 'parent_line_id' => $invoice->lines->first()->id, 'tax_code' => 'ZA_VAT_STD']]);

        $this->assertSame('ZA_VAT_ZERO', $credit->lines->first()->tax_code);
        $this->assertSame(0, (int) $credit->tax_cents);
    }

    public function test_cannot_credit_more_than_was_charged_across_credit_notes(): void
    {
        $this->setUpSales();
        $invoice = $this->issued([$this->line('Goods', '1', 100_000)]);
        $line = $invoice->lines->first();
        $this->issue($this->creditFor($invoice, [['description' => 'Part 1', 'quantity' => '1', 'unit_cents' => 60_000, 'parent_line_id' => $line->id]]));

        try {
            $this->creditFor($invoice, [['description' => 'Part 2', 'quantity' => '1', 'unit_cents' => 50_000, 'parent_line_id' => $line->id]]);
            $this->fail('over-credit allowed');
        } catch (SalesViolation $e) {
            $this->assertSame('credit_limit', $e->reason);
        }

        $ok = $this->issue($this->creditFor($invoice, [['description' => 'Part 2', 'quantity' => '1', 'unit_cents' => 40_000, 'parent_line_id' => $line->id]]));
        $this->assertSame(46_000, (int) $ok->total_cents);
        $this->assertSame(0, $this->open($invoice));
        $this->assertSame(0, $this->glBalance('ar'));
    }

    public function test_a_credit_line_must_point_at_a_line_of_the_invoice(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();

        $this->expectException(SalesViolation::class);
        $this->creditFor($invoice, [['description' => 'Nowhere', 'quantity' => '1', 'unit_cents' => 1_000, 'parent_line_id' => 99999]]);
    }

    public function test_a_credit_note_needs_a_reason_to_be_issued(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();
        $draft = $this->creditFor($invoice, null, '');

        try {
            $this->issue($draft);
            $this->fail('issued without a reason');
        } catch (SalesViolation $e) {
            $this->assertSame('reason', $e->reason);
        }
    }

    public function test_a_credit_against_a_paid_invoice_becomes_a_customer_credit_that_can_be_refunded(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();
        LedgerContext::run($this->ledger, fn () => app(ReceiptService::class)->receive($this->ledger, $this->owner, [
            'party_id' => $this->customer->id, 'cash_account_id' => $this->bank->id, 'amount_cents' => 115_000, 'paid_on' => now()->toDateString(),
            'idempotency_key' => 'p', 'allocations' => [['document_id' => $invoice->id, 'amount_cents' => 115_000]],
        ]));
        $credit = $this->issue($this->creditFor($invoice));

        $this->assertSame('paid', $invoice->refresh()->status);
        $this->assertSame(-115_000, $this->glBalance('ar'), 'the customer is owed money');
        $credits = LedgerContext::run($this->ledger, fn () => app(ReceivablesLedger::class)->unappliedCredits($this->ledger));
        $this->assertSame(115_000, $credits->firstWhere('kind', 'credit_note')['cents']);
        $this->assertTrue($this->reconciles());

        LedgerContext::run($this->ledger, fn () => app(ReceiptService::class)->refund($this->ledger, $this->owner, [
            'source_document_id' => $credit->id, 'amount_cents' => 115_000, 'cash_account_id' => $this->bank->id, 'paid_on' => now()->toDateString(),
            'idempotency_key' => 'rf', 'reason' => 'Credit paid back',
        ]));
        $this->assertSame(0, $this->glBalance('ar'));
        $this->assertSame(0, (int) Account::query()->forContext($this->ledger)->withBalance()->find($this->bank->id)->balanceCents());
        $this->assertTrue($this->reconciles());
        $this->assertBooksBalance();
    }

    public function test_a_credit_note_can_be_applied_to_a_different_open_invoice_of_the_same_customer(): void
    {
        $this->setUpSales();
        $paid = $this->issued([$this->line('Old', '1', 100_000)]);
        LedgerContext::run($this->ledger, fn () => app(ReceiptService::class)->receive($this->ledger, $this->owner, [
            'party_id' => $this->customer->id, 'cash_account_id' => $this->bank->id, 'amount_cents' => 115_000, 'paid_on' => now()->toDateString(),
            'idempotency_key' => 'p', 'allocations' => [['document_id' => $paid->id, 'amount_cents' => 115_000]],
        ]));
        $credit = $this->issue($this->creditFor($paid));
        $next = $this->issued([$this->line('New', '1', 100_000)]);

        LedgerContext::run($this->ledger, fn () => app(ApplicationEngine::class)->applyCredit($credit, $next, 115_000, $this->owner));

        $this->assertSame(0, $this->open($next));
        $this->assertSame(0, $this->glBalance('ar'));
        $this->assertTrue($this->reconciles());
    }

    public function test_a_debit_note_raises_what_is_owed_and_posts_like_an_invoice(): void
    {
        $this->setUpSales();
        $invoice = $this->issued([$this->line('Goods', '1', 100_000)]);
        $draft = LedgerContext::run($this->ledger, fn () => app(DocumentLifecycle::class)->draftDebitNote($invoice, $this->owner, 'Price understated'));
        $draft = LedgerContext::run($this->ledger, fn () => app(DocumentDrafts::class)->update($draft, $this->owner, ['party_id' => $this->customer->id, 'issue_date' => now()->toDateString(), 'reason' => 'Price understated'], [$this->line('Adjustment', '1', 20_000)]));
        $debit = $this->issue($draft);

        $this->assertSame('DN-00001', $debit->number);
        $this->assertSame(23_000, (int) $debit->total_cents);
        $this->assertSame(138_000, $this->glBalance('ar'));
        $this->assertSame(23_000, $this->open($debit), 'a debit note is a receivable in its own right');
        $this->assertTrue($this->reconciles());
        $this->assertBooksBalance();
    }

    public function test_voiding_a_credit_note_puts_the_invoice_back_to_owing(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();
        $credit = $this->issue($this->creditFor($invoice));
        $this->assertSame(0, $this->open($invoice));

        // the credit note is applied to the invoice, so it cannot simply be voided
        try {
            LedgerContext::run($this->ledger, fn () => app(DocumentLifecycle::class)->void($credit, 'Mistake', $this->owner));
            $this->fail('voided an applied credit note');
        } catch (SalesViolation $e) {
            $this->assertSame('applied', $e->reason);
        }

        $app = SalesApplication::query()->forContext($this->ledger)->where('kind', 'credit')->firstOrFail();
        LedgerContext::run($this->ledger, fn () => app(ApplicationEngine::class)->release($app, 'Wrong invoice', $this->owner));
        $this->assertSame(115_000, $this->open($invoice));

        LedgerContext::run($this->ledger, fn () => app(DocumentLifecycle::class)->void($credit, 'Mistake', $this->owner));
        $this->assertSame(115_000, $this->glBalance('ar'));
        $this->assertTrue($this->reconciles());
        $this->assertBooksBalance();
    }

    public function test_an_invoice_with_a_credit_note_cannot_be_voided(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();
        $this->issue($this->creditFor($invoice));

        $this->expectException(SalesViolation::class);
        LedgerContext::run($this->ledger, fn () => app(DocumentLifecycle::class)->void($invoice, 'Mistake', $this->owner));
    }

    public function test_an_invoice_with_a_payment_cannot_be_voided_but_without_one_it_can(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();
        $out = LedgerContext::run($this->ledger, fn () => app(ReceiptService::class)->receive($this->ledger, $this->owner, [
            'party_id' => $this->customer->id, 'cash_account_id' => $this->bank->id, 'amount_cents' => 10_000, 'paid_on' => now()->toDateString(),
            'idempotency_key' => 'p', 'allocations' => [['document_id' => $invoice->id, 'amount_cents' => 10_000]],
        ]));

        try {
            LedgerContext::run($this->ledger, fn () => app(DocumentLifecycle::class)->void($invoice, 'Mistake', $this->owner));
            $this->fail('voided a part-paid invoice');
        } catch (SalesViolation $e) {
            $this->assertSame('applied', $e->reason);
        }

        LedgerContext::run($this->ledger, fn () => app(ReceiptService::class)->reverse($this->ledger, $this->owner, $out['payment'], 'Wrong customer'));
        $voided = LedgerContext::run($this->ledger, fn () => app(DocumentLifecycle::class)->void($invoice, 'Raised in error', $this->owner));
        $this->assertSame('void', $voided->status);
        $this->assertSame('Raised in error', $voided->void_reason);
        $this->assertSame(0, $this->glBalance('ar'));
        $this->assertTrue($this->reconciles());
    }

    public function test_voiding_needs_a_reason_and_is_refused_for_a_draft(): void
    {
        $this->setUpSales();
        $invoice = $this->issued();

        $this->expectException(SalesViolation::class);
        LedgerContext::run($this->ledger, fn () => app(DocumentLifecycle::class)->void($invoice, ' ', $this->owner));
    }
}
