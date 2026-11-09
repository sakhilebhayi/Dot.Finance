<?php

namespace Tests\Feature\Sales;

use App\Enums\FinanceRole;
use App\Models\Account;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\LedgerSetting;
use App\Models\Controls\LedgerMemberRole;
use App\Models\Sales\SalesDocument;
use App\Models\Shared\Party;
use App\Models\Shared\Payment;
use App\Models\Transaction;
use App\Services\Controls\ApprovalPolicies;
use App\Services\Controls\ApprovalService;
use App\Services\Sales\DocumentDrafts;
use App\Services\Sales\DocumentIssuer;
use App\Services\Sales\ReceiptService;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsSales;
use Tests\TestCase;

/** Another ledger's ids, smuggled into the bodies of Sales forms, reach nothing and write nothing. */
class SalesIsolationTest extends TestCase
{
    use BuildsSales;
    use RefreshDatabase;

    private function as(): static
    {
        return $this->inCompany($this->owner, $this->company);
    }

    /** @return array<string, int> row counts of every table a Sales write could touch */
    private function counts(): array
    {
        return collect(['sales_documents', 'sales_document_lines', 'payments', 'payment_allocations', 'sales_applications', 'journal_entries', 'transactions', 'sales_transaction_links', 'sales_refunds', 'sales_write_offs'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }

    public function test_a_customer_from_another_ledger_cannot_be_put_on_an_invoice_or_paid_from(): void
    {
        $this->setUpSales();
        $stranger = $this->user();
        $foreign = Party::create(['user_id' => $stranger->id, 'type' => 'customer', 'name' => 'Foreign Co', 'status' => 'active']);
        $before = $this->counts();

        $this->as()->post(route('sales.documents.store'), ['kind' => 'tax_invoice', 'party_id' => $foreign->id, 'currency' => 'ZAR', 'issue_date' => now()->toDateString(), 'lines' => [['description' => 'x', 'quantity' => '1', 'unit_price' => '10.00', 'tax_code' => 'ZA_VAT_STD']]])
            ->assertSessionHasErrors('sales');
        $this->as()->post(route('sales.payments.store'), ['party_id' => $foreign->id, 'cash_account_id' => $this->bank->id, 'amount' => '10.00', 'paid_on' => now()->toDateString(), 'idempotency_key' => 'iso-1'])
            ->assertSessionHasErrors('sales');

        $this->assertSame($before, $this->counts());
    }

    public function test_another_ledgers_bank_account_or_invoice_in_a_payment_writes_nothing(): void
    {
        $this->setUpSales();
        $stranger = $this->user();
        $foreignBank = Account::factory()->create(['user_id' => $stranger->id, 'currency' => 'ZAR']);
        $other = $this->company($stranger, 'Other Ltd');
        $foreignInvoice = $this->foreignInvoice($other, $stranger);
        $mine = $this->issued();
        $before = $this->counts();

        $this->as()->post(route('sales.payments.store'), ['party_id' => $this->customer->id, 'cash_account_id' => $foreignBank->id, 'amount' => '10.00', 'paid_on' => now()->toDateString(), 'idempotency_key' => 'iso-2'])
            ->assertSessionHasErrors('sales');
        $this->as()->post(route('sales.payments.store'), ['party_id' => $this->customer->id, 'cash_account_id' => $this->bank->id, 'amount' => '10.00', 'paid_on' => now()->toDateString(), 'idempotency_key' => 'iso-3', 'allocations' => [$foreignInvoice->id => '10.00']])
            ->assertSessionHasErrors('sales');
        $this->as()->post(route('sales.documents.apply-credit', $mine), ['credit_id' => $foreignInvoice->id, 'amount' => '1.00'])->assertNotFound();

        $this->assertSame($before, $this->counts());
    }

    private function foreignInvoice($team, $user): SalesDocument
    {
        $ctx = LedgerContext::company($team);
        $this->enableAccounting($ctx);

        return LedgerContext::run($ctx, function () use ($ctx, $user) {
            $party = Party::create($ctx->ownerAttributes() + ['type' => 'customer', 'name' => 'Theirs', 'status' => 'active', 'billing_address' => ['line1' => '1 X']]);
            $draft = app(DocumentDrafts::class)->create($ctx, $user, ['kind' => 'tax_invoice', 'party_id' => $party->id, 'currency' => 'ZAR', 'issue_date' => now()->toDateString()], [['description' => 'x', 'quantity' => '1', 'unit_cents' => 10_000]]);

            return app(DocumentIssuer::class)->issue($draft, $user)['document'];
        });
    }

    public function test_another_ledgers_income_account_on_a_line_or_a_document_id_is_refused_or_not_found(): void
    {
        $this->setUpSales();
        $stranger = $this->user();
        $other = $this->company($stranger, 'Other Ltd');
        $foreign = $this->foreignInvoice($other, $stranger);
        $foreignGl = GlAccount::query()->forContext(LedgerContext::company($other))->where('class', 'income')->firstOrFail();
        $before = $this->counts();

        $this->as()->post(route('sales.documents.store'), ['kind' => 'tax_invoice', 'party_id' => $this->customer->id, 'currency' => 'ZAR', 'issue_date' => now()->toDateString(),
            'lines' => [['description' => 'x', 'quantity' => '1', 'unit_price' => '10.00', 'tax_code' => 'ZA_VAT_STD', 'income_account_id' => $foreignGl->id]]])->assertSessionHasErrors('sales');
        $this->as()->get(route('sales.documents.show', $foreign))->assertNotFound();
        $this->as()->get(route('sales.documents.pdf', $foreign))->assertNotFound();
        $this->as()->post(route('sales.documents.credit', $foreign))->assertNotFound();
        $this->as()->post(route('sales.documents.issue', $foreign))->assertNotFound();

        $this->assertSame($before, $this->counts());
        $this->assertSame('issued', SalesDocument::query()->forContext(LedgerContext::company($other))->first()->status, 'their invoice is untouched');
    }

    public function test_linking_another_ledgers_transaction_is_not_found(): void
    {
        $this->setUpSales();
        $stranger = $this->user();
        $theirAccount = Account::factory()->create(['user_id' => $stranger->id]);
        $theirs = Transaction::factory()->create(['account_id' => $theirAccount->id, 'type' => 'income', 'amount_cents' => 5_000]);
        $before = $this->counts();

        $this->as()->post(route('sales.customers.link-transaction', $this->customer), ['transaction_id' => $theirs->id])->assertNotFound();

        $this->assertSame($before, $this->counts());
    }

    public function test_an_income_transaction_can_be_linked_to_a_customer_and_a_settlement_row_cannot(): void
    {
        $this->setUpSales();
        $cash = Transaction::factory()->create(['account_id' => $this->bank->id, 'type' => 'income', 'amount_cents' => 5_000, 'description' => 'Cash sale at the counter']);
        $invoice = $this->issued();

        $this->as()->post(route('sales.customers.link-transaction', $this->customer), ['transaction_id' => $cash->id, 'document_id' => $invoice->id])->assertSessionHasNoErrors();
        $this->as()->get(route('sales.customers.show', $this->customer))->assertOk()->assertSee('Cash sale at the counter');

        // the cash leg of a payment is not an ordinary income row
        $out = LedgerContext::run($this->ledger, fn () => app(ReceiptService::class)->receive($this->ledger, $this->owner, ['party_id' => $this->customer->id, 'cash_account_id' => $this->bank->id, 'amount_cents' => 1_000, 'paid_on' => now()->toDateString(), 'idempotency_key' => 'lnk']));
        $this->as()->post(route('sales.customers.link-transaction', $this->customer), ['transaction_id' => $out['payment']->transaction_id])->assertNotFound();
    }

    public function test_the_cash_row_of_a_payment_cannot_be_edited_or_deleted_by_hand(): void
    {
        $this->setUpSales();
        $out = LedgerContext::run($this->ledger, fn () => app(ReceiptService::class)->receive($this->ledger, $this->owner, ['party_id' => $this->customer->id, 'cash_account_id' => $this->bank->id, 'amount_cents' => 1_000, 'paid_on' => now()->toDateString(), 'idempotency_key' => 'lock']));
        $row = Transaction::query()->forContext($this->ledger)->findOrFail($out['payment']->transaction_id);

        $this->as()->get(route('transactions.edit', $row))->assertForbidden();
        $this->as()->delete(route('transactions.destroy', $row))->assertForbidden();
        $this->assertNull(Transaction::query()->forContext($this->ledger)->findOrFail($row->id)->deleted_at);
        $this->assertBooksBalance();
    }

    // ---- approvals through the Controls screens ----

    public function test_an_invoice_is_approved_and_issued_through_the_approvals_screen(): void
    {
        $this->setUpSales();
        $approver = $this->memberOf($this->company, 'viewer');
        LedgerMemberRole::create(['team_id' => $this->company->id, 'member_user_id' => $approver->id, 'role' => FinanceRole::Approver->value, 'granted_by' => $this->owner->id, 'granted_at' => now()]);
        $bookkeeper = $this->memberOf($this->company, 'editor');
        app(ApprovalPolicies::class)->create($this->owner, $this->ledger, ['subject_type' => 'invoice', 'name' => 'Invoices over R10,000', 'threshold_cents' => 1_000_000, 'currency' => 'ZAR']);

        $draft = $this->draft([$this->line('Big', '1', 2_000_000)]);
        $this->inCompany($bookkeeper, $this->company)->post(route('sales.documents.issue', $draft))->assertSessionHas('status');
        $this->assertSame('awaiting_approval', $draft->refresh()->status);

        $request = LedgerContext::run($this->ledger, fn () => app(ApprovalService::class)->latestFor($draft));
        $this->inCompany($approver, $this->company)->get(route('controls.approvals.index'))->assertOk()->assertSee('Tax invoice');
        $this->inCompany($approver, $this->company)->post(route('controls.approvals.approve', $request))->assertSessionHasNoErrors();

        $this->assertSame('issued', $draft->refresh()->status);
        $this->assertSame('INV-00001', $draft->number);
        $this->assertSame(2_300_000, $this->glBalance('ar'));
    }

    public function test_when_the_books_refuse_the_issue_the_approval_is_refused_with_the_reason_and_nothing_is_decided(): void
    {
        $this->setUpSales();
        $approver = $this->memberOf($this->company, 'viewer');
        LedgerMemberRole::create(['team_id' => $this->company->id, 'member_user_id' => $approver->id, 'role' => FinanceRole::Approver->value, 'granted_by' => $this->owner->id, 'granted_at' => now()]);
        $bookkeeper = $this->memberOf($this->company, 'editor');
        app(ApprovalPolicies::class)->create($this->owner, $this->ledger, ['subject_type' => 'invoice', 'name' => 'Invoices over R10,000', 'threshold_cents' => 1_000_000, 'currency' => 'ZAR']);
        $draft = $this->draft([$this->line('Big', '1', 2_000_000)]);
        $this->inCompany($bookkeeper, $this->company)->post(route('sales.documents.issue', $draft));
        $request = LedgerContext::run($this->ledger, fn () => app(ApprovalService::class)->latestFor($draft));

        // the books are closed through today while it waited
        LedgerSetting::query()->forContext($this->ledger)->update(['books_closed_through' => now()->toDateString()]);

        $this->inCompany($approver, $this->company)->post(route('controls.approvals.approve', $request))->assertSessionHasErrors('controls');
        $this->assertSame('pending', $request->refresh()->status, 'the decision rolled back with the failed issue');
        $this->assertSame('awaiting_approval', $draft->refresh()->status);
        $this->assertNull($draft->number);
    }
}
