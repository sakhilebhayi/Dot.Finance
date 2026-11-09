<?php

namespace Tests\Feature\Sales;

use App\Models\Accounting\JournalLine;
use App\Services\Sales\ReceiptService;
use App\Services\Sales\ReceivablesLedger;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSales;
use Tests\TestCase;

class SalesSmokeTest extends TestCase
{
    use BuildsSales;
    use RefreshDatabase;

    public function test_issue_post_pay_reconcile(): void
    {
        $this->setUpSales();
        $invoice = $this->issued([$this->line('Consulting', '2', 100_000)]);

        $this->assertSame('INV-00001', $invoice->number);
        $this->assertSame(230_000, (int) $invoice->total_cents);
        $this->assertSame(230_000, $this->glBalance('ar'));
        $this->assertSame(-30_000, $this->glBalance('vat_output'));

        $result = LedgerContext::run($this->ledger, fn () => app(ReceiptService::class)->receive($this->ledger, $this->owner, [
            'party_id' => $this->customer->id, 'cash_account_id' => $this->bank->id, 'amount_cents' => 230_000, 'paid_on' => now()->toDateString(),
            'idempotency_key' => 'k1', 'allocations' => [['document_id' => $invoice->id, 'amount_cents' => 230_000]],
        ]));

        $this->assertSame('paid', $invoice->refresh()->status);
        $this->assertSame(0, $this->glBalance('ar'));
        $this->assertSame(230_000, $this->bankGl());
        $rec = LedgerContext::run($this->ledger, fn () => app(ReceivablesLedger::class)->reconcile($this->ledger));
        $this->assertTrue($rec['ok']);
        $this->assertBooksBalance();
    }

    private function bankGl(): int
    {
        return (int) JournalLine::query()->forContext($this->ledger)->where('cash_account_id', $this->bank->id)->selectRaw('SUM(debit_cents)-SUM(credit_cents) as n')->value('n');
    }
}
