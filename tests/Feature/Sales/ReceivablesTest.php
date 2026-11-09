<?php

namespace Tests\Feature\Sales;

use App\Models\Sales\SalesDocument;
use App\Models\Shared\Party;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\PostingLine;
use App\Services\Accounting\PostingRequest;
use App\Services\Sales\DocumentDrafts;
use App\Services\Sales\DocumentIssuer;
use App\Services\Sales\ReceiptService;
use App\Services\Sales\ReceivablesLedger;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSales;
use Tests\TestCase;

class ReceivablesTest extends TestCase
{
    use BuildsSales;
    use RefreshDatabase;

    private function ledgerService(): ReceivablesLedger
    {
        return app(ReceivablesLedger::class);
    }

    /** An invoice issued today but due $daysFromToday from now (negative: overdue). */
    private function dueIn(int $daysFromToday, int $unit = 100_000, ?Party $customer = null): SalesDocument
    {
        $draft = $this->draft([$this->line('Work', '1', $unit)], ['due_date' => now()->addDays(max(0, $daysFromToday))->toDateString()], $customer);
        if ($daysFromToday < 0) {
            // the invoice is older than today: back-date it (draft) so its due date is in the past
            $draft = DocumentDrafts::class ? LedgerContext::run($this->ledger, fn () => app(DocumentDrafts::class)->update($draft, $this->owner, [
                'party_id' => ($customer ?? $this->customer)->id, 'issue_date' => now()->addDays($daysFromToday - 30)->toDateString(), 'due_date' => now()->addDays($daysFromToday)->toDateString(),
            ], [$this->line('Work', '1', $unit)])) : $draft;
        }

        return LedgerContext::run($this->ledger, fn () => app(DocumentIssuer::class)->issue($draft, $this->owner)['document']);
    }

    public function test_ageing_buckets_sort_open_invoices_by_days_past_due(): void
    {
        $this->setUpSales();
        $this->dueIn(5, 100_000);      // current
        $this->dueIn(-10, 200_000);    // 1-30
        $this->dueIn(-45, 300_000);    // 31-60
        $this->dueIn(-75, 400_000);    // 61-90
        $this->dueIn(-100, 500_000);   // 91-120
        $this->dueIn(-200, 600_000);   // over 120

        $age = LedgerContext::run($this->ledger, fn () => $this->ledgerService()->ageing($this->ledger));
        $totals = $age['totals'];

        $this->assertSame(115_000, $totals['current']);
        $this->assertSame(230_000, $totals['d30']);
        $this->assertSame(345_000, $totals['d60']);
        $this->assertSame(460_000, $totals['d90']);
        $this->assertSame(575_000, $totals['d120']);
        $this->assertSame(690_000, $totals['d120p']);
        $this->assertSame(115_000 * 21, $totals['total']);
        $this->assertCount(1, $age['rows']);
        $this->assertSame(6, $age['rows'][0]['count']);
    }

    public function test_ageing_total_equals_the_receivables_account(): void
    {
        $this->setUpSales();
        $a = $this->dueIn(-10);
        $this->dueIn(5);
        LedgerContext::run($this->ledger, fn () => app(ReceiptService::class)->receive($this->ledger, $this->owner, [
            'party_id' => $this->customer->id, 'cash_account_id' => $this->bank->id, 'amount_cents' => 40_000, 'paid_on' => now()->toDateString(),
            'idempotency_key' => 'p', 'allocations' => [['document_id' => $a->id, 'amount_cents' => 40_000]],
        ]));

        $age = LedgerContext::run($this->ledger, fn () => $this->ledgerService()->ageing($this->ledger));
        $rec = LedgerContext::run($this->ledger, fn () => $this->ledgerService()->reconcile($this->ledger));

        $this->assertSame($this->glBalance('ar'), $age['totals']['total']);
        $this->assertTrue($rec['ok']);
        $this->assertSame(0, $rec['difference_cents']);
        $this->assertSame($rec['ledger_cents'], $rec['documents_cents']);
    }

    public function test_unapplied_money_shows_as_on_account_and_still_reconciles(): void
    {
        $this->setUpSales();
        $this->dueIn(-10);
        LedgerContext::run($this->ledger, fn () => app(ReceiptService::class)->receive($this->ledger, $this->owner, [
            'party_id' => $this->customer->id, 'cash_account_id' => $this->bank->id, 'amount_cents' => 20_000, 'paid_on' => now()->toDateString(), 'idempotency_key' => 'p',
        ]));

        $age = LedgerContext::run($this->ledger, fn () => $this->ledgerService()->ageing($this->ledger));
        $this->assertSame(-20_000, $age['totals']['on_account']);
        $this->assertSame(95_000, $age['totals']['total']);
        $this->assertTrue(LedgerContext::run($this->ledger, fn () => $this->ledgerService()->reconcile($this->ledger))['ok']);
    }

    public function test_the_other_basis_ages_from_the_invoice_date(): void
    {
        $this->setUpSales();
        $this->dueIn(-10);   // issued 40 days ago, due 10 days ago

        $due = LedgerContext::run($this->ledger, fn () => $this->ledgerService()->ageing($this->ledger, 'due'));
        $invoice = LedgerContext::run($this->ledger, fn () => $this->ledgerService()->ageing($this->ledger, 'invoice'));

        $this->assertSame(115_000, $due['totals']['d30']);
        $this->assertSame(115_000, $invoice['totals']['d60']);
    }

    public function test_a_manual_journal_to_receivables_is_shown_as_a_difference_not_hidden(): void
    {
        $this->setUpSales();
        $this->issued();
        LedgerContext::run($this->ledger, fn () => app(JournalPoster::class)->post(new PostingRequest(
            CarbonImmutable::today(), 'manual', 'adj-1', 1, 'Opening debtors typed by hand',
            [new PostingLine('ar', 5_000), new PostingLine('suspense', 0, 5_000)],
        )));

        $rec = LedgerContext::run($this->ledger, fn () => $this->ledgerService()->reconcile($this->ledger));

        $this->assertFalse($rec['ok']);
        $this->assertSame(5_000, $rec['difference_cents']);
        $this->assertSame(5_000, $rec['unattributed_cents'], 'a posting with no customer on it');
    }

    public function test_a_difference_on_one_customer_is_shown_for_that_customer(): void
    {
        $this->setUpSales();
        $this->issued();
        LedgerContext::run($this->ledger, fn () => app(JournalPoster::class)->post(new PostingRequest(
            CarbonImmutable::today(), 'manual', 'adj-2', 1, 'Typed by hand against Acme',
            [new PostingLine('ar', 7_000, partyId: $this->customer->id), new PostingLine('suspense', 0, 7_000)],
        )));

        $rec = LedgerContext::run($this->ledger, fn () => $this->ledgerService()->reconcile($this->ledger));
        $row = collect($rec['parties'])->firstWhere('party_id', $this->customer->id);

        $this->assertSame(7_000, $row['difference_cents']);
        $this->assertSame('Acme Retail', $row['name']);
    }

    public function test_the_summary_for_the_dashboard(): void
    {
        $this->setUpSales();
        $this->dueIn(-10);
        $this->dueIn(3);
        $this->dueIn(40);

        $s = LedgerContext::run($this->ledger, fn () => $this->ledgerService()->summary($this->ledger));

        $this->assertSame(345_000, $s['owed_base_cents']);
        $this->assertSame(115_000, $s['overdue_base_cents']);
        $this->assertSame(1, $s['overdue_count']);
        $this->assertSame(115_000, $s['due_soon_base_cents']);
        $this->assertSame(3, $s['open_count']);
        $this->assertTrue($s['reconciles']);
        $this->assertSame(10, $s['oldest_overdue_days']);
    }

    public function test_a_customer_balance_is_invoices_less_credits_and_unapplied_money(): void
    {
        $this->setUpSales();
        $this->dueIn(5);
        LedgerContext::run($this->ledger, fn () => app(ReceiptService::class)->receive($this->ledger, $this->owner, [
            'party_id' => $this->customer->id, 'cash_account_id' => $this->bank->id, 'amount_cents' => 15_000, 'paid_on' => now()->toDateString(), 'idempotency_key' => 'p',
        ]));

        $this->assertSame(100_000, LedgerContext::run($this->ledger, fn () => $this->ledgerService()->customerBalanceBase($this->ledger, $this->customer->id)));
    }

    public function test_only_open_items_of_this_ledger_are_counted(): void
    {
        $this->setUpSales();
        $this->issued();
        $other = $this->user();
        $theirs = Party::create(['user_id' => $other->id, 'type' => 'customer', 'name' => 'Not mine', 'status' => 'active']);

        $this->assertSame(0, LedgerContext::run($this->ledger, fn () => $this->ledgerService()->openItems($this->ledger, $theirs->id))->count());
    }
}
