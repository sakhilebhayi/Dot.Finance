<?php

namespace Tests\Feature\Purchases;

use App\Models\Purchases\Bill;
use App\Models\Purchases\ExpenseClaim;
use App\Models\Purchases\PaymentRun;
use App\Models\Purchases\RecurringBill;
use App\Models\Purchases\SupplierBankDetail;
use App\Models\Shared\Payment;
use App\Services\Purchases\ClaimWorkflow;
use App\Services\Purchases\PaymentRunService;
use App\Services\Purchases\ReceiptCapture;
use App\Services\Purchases\SupplierBankDetails;
use App\Services\Purchases\SupplierPayments;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsPurchases;
use Tests\Support\MakesPdfs;
use Tests\TestCase;

class PagesTest extends TestCase
{
    use BuildsPurchases;
    use RefreshDatabase;

    private Bill $bill;

    private Bill $draft;

    private ExpenseClaim $claim;

    private Payment $payment;

    private PaymentRun $run;

    private RecurringBill $schedule;

    private \App\Models\Shared\Attachment $inbox;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpPurchases();

        $this->bill = $this->postedBill(['supplier_reference' => 'P-1', 'bill_date' => '2026-10-01', 'due_date' => '2026-10-31']);
        $this->draft = $this->draftBill(['supplier_reference' => 'D-1', 'lines' => [$this->line(['unit_price_cents' => 12_300])]]);
        $this->payment = app(SupplierPayments::class)->pay($this->ctx, $this->owner, ['party_id' => $this->supplier->id, 'cash_account_id' => $this->bank->id, 'paid_on' => '2026-11-03', 'reference' => 'EFT-7', 'idempotency_key' => 'pg', 'allocations' => [['type' => 'bill', 'id' => $this->bill->id, 'amount_cents' => 50_000]]]);
        $this->claim = app(ClaimWorkflow::class)->saveDraft($this->ctx, $this->owner, ['title' => 'Trip', 'claim_date' => '2026-11-04', 'lines' => [['spent_on' => '2026-11-04', 'merchant' => 'Shell', 'description' => 'Fuel', 'receipt_type' => 'none', 'gl_account_id' => $this->gl('6170')->id, 'tax_code' => 'ZA_VAT_ZERO', 'gross_cents' => 20_000]]]);
        $this->schedule = RecurringBill::query()->create($this->ctx->ownerAttributes() + ['party_id' => $this->supplier->id, 'name' => 'Rent', 'frequency' => 'monthly', 'interval_count' => 1, 'start_on' => '2026-12-01', 'next_run_on' => '2026-12-01', 'status' => 'active', 'days_until_due' => 14, 'currency' => 'ZAR', 'template' => [['description' => 'Rent', 'gl_account_id' => $this->gl('6100')->id, 'quantity' => '1', 'unit_price_cents' => 100_000, 'tax_code' => 'ZA_VAT_STD']], 'created_by' => $this->owner->id]);
        $this->run = app(PaymentRunService::class)->create($this->ctx, $this->owner, ['due_by' => '2026-11-30', 'paid_on' => '2026-11-10', 'cash_account_id' => $this->bank->id, 'idempotency_key' => 'run-pg', 'selection' => [['type' => 'bill', 'id' => $this->bill->id]]]);
        app(SupplierBankDetails::class)->request($this->ctx, $this->owner, $this->supplier, ['bank_name' => 'FNB', 'account_holder' => 'Acme', 'account_number' => '70011223344556', 'branch_code' => '250655'], 'Called the supplier on the number on file', true);
        $path = tempnam(sys_get_temp_dir(), 'fx');
        file_put_contents($path, MakesPdfs::text(MakesPdfs::invoiceLines('INV-PG')));
        $this->inbox = app(ReceiptCapture::class)->capture($this->ctx, $this->owner, new UploadedFile($path, 'inv.pdf', null, null, true));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, string> name => url, for every page that renders with the data from setUp */
    private function pages(): array
    {
        return [
            'overview' => route('purchases.index'), 'bills' => route('purchases.bills.index'), 'bills filtered' => route('purchases.bills.index', ['status' => 'overdue', 'q' => 'P-1']),
            'bill create' => route('purchases.bills.create', ['party_id' => $this->supplier->id]), 'bill show' => route('purchases.bills.show', $this->bill), 'draft show' => route('purchases.bills.show', $this->draft),
            'draft edit' => route('purchases.bills.edit', $this->draft), 'credit form' => route('purchases.bills.credit.create', $this->bill),
            'suppliers' => route('purchases.suppliers.index'), 'supplier create' => route('purchases.suppliers.create'), 'supplier show' => route('purchases.suppliers.show', $this->supplier),
            'supplier edit' => route('purchases.suppliers.edit', $this->supplier), 'supplier bank' => route('purchases.suppliers.bank.edit', $this->supplier),
            'statement' => route('purchases.suppliers.statement', $this->supplier), 'claims' => route('purchases.claims.index'), 'claim create' => route('purchases.claims.create'),
            'claim show' => route('purchases.claims.show', $this->claim), 'claim edit' => route('purchases.claims.edit', $this->claim), 'payments' => route('purchases.payments.index'),
            'payment create' => route('purchases.payments.create', ['party_id' => $this->supplier->id, 'bill' => $this->bill->id]), 'payment show' => route('purchases.payments.show', $this->payment),
            'runs' => route('purchases.runs.index'), 'run create' => route('purchases.runs.create', ['due_by' => '2026-12-31']), 'run show' => route('purchases.runs.show', $this->run),
            'receipts' => route('purchases.receipts.index'), 'receipt show' => route('purchases.receipts.show', $this->inbox), 'documents' => route('purchases.documents.index'),
            'ap' => route('purchases.ap.index'), 'settings' => route('purchases.settings.edit'), 'recurring' => route('purchases.recurring.index'), 'recurring create' => route('purchases.recurring.create'),
            'dashboard' => route('dashboard'),
        ];
    }

    public function test_every_page_renders_for_the_owner_with_real_data_and_carries_no_inline_style_or_script(): void
    {
        $this->inCompany($this->owner, $this->company);

        foreach ($this->pages() as $name => $url) {
            $response = $this->get($url);
            $response->assertOk();
            $html = $response->getContent();
            $this->assertDoesNotMatchRegularExpression('/\sstyle="/i', $html, "{$name}: no inline style attribute");
            $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*nonce=)(?![^>]*src=)/i', $html, "{$name}: no un-nonced inline script");
            $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit|load)=/i', $html, "{$name}: no inline handler");
            $this->assertSame(1, substr_count($html, '<h1'), "{$name}: exactly one h1");
        }
    }

    public function test_csv_downloads_work(): void
    {
        $this->inCompany($this->owner, $this->company);

        $this->get(route('purchases.suppliers.statement', [$this->supplier, 'format' => 'csv']))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->get(route('purchases.ap.index', ['format' => 'csv']))->assertOk();
        $this->get(route('purchases.runs.csv', $this->run))->assertOk();
    }

    public function test_the_bank_account_number_never_appears_on_any_page(): void
    {
        $this->inCompany($this->owner, $this->company);

        foreach ($this->pages() as $name => $url) {
            $this->assertStringNotContainsString('70011223344556', $this->get($url)->getContent(), "{$name} must show the account masked");
        }
        $this->assertStringContainsString('account ending 4556', $this->get(route('purchases.suppliers.show', $this->supplier))->getContent());
    }

    public function test_the_navigation_and_state_line_pick_up_purchases(): void
    {
        $this->inCompany($this->owner, $this->company);

        $html = $this->get(route('dashboard'))->getContent();
        $this->assertStringContainsString(route('purchases.bills.index'), $html);
        $this->assertStringContainsString(route('purchases.suppliers.index'), $html);
        $this->assertStringContainsString(route('purchases.claims.index'), $html);
        $this->assertStringContainsString('Supplier bills due', $html, 'the dashboard panel');
        $this->assertStringContainsString('supplier bill is overdue', $html, 'the state line');
    }

    // ── a whole journey through the browser's routes ────────────────────

    public function test_capture_submit_pay_and_reverse_through_the_routes(): void
    {
        $this->inCompany($this->owner, $this->company);

        $lines = [['description' => 'Beans', 'gl_account_id' => $this->gl('6190')->id, 'quantity' => '4', 'unit_price' => '250.00', 'tax_code' => 'ZA_VAT_STD']];
        $response = $this->post(route('purchases.bills.store'), [
            'party_id' => $this->supplier->id, 'bill_date' => '2026-11-04', 'supplier_reference' => 'WEB-1', 'document_type' => 'tax_invoice', 'lines' => $lines,
            'total' => '1', 'tax_cents' => '1', 'client_token' => 'web-1', 'submit_now' => '1',
        ]);

        $bill = Bill::query()->forContext($this->ctx)->where('supplier_reference', 'WEB-1')->firstOrFail();
        $response->assertRedirect(route('purchases.bills.show', $bill));
        $this->assertSame(['open', 115_000], [$bill->status, $bill->total_cents], 'the server worked out 4 x 250.00 + 15% itself');

        // the same token again changes nothing
        $this->post(route('purchases.bills.store'), ['party_id' => $this->supplier->id, 'bill_date' => '2026-11-04', 'client_token' => 'web-1', 'lines' => $lines])->assertRedirect();
        $this->assertSame(1, Bill::query()->forContext($this->ctx)->where('supplier_reference', 'WEB-1')->count());

        $this->post(route('purchases.payments.store'), [
            'party_id' => $this->supplier->id, 'cash_account_id' => $this->bank->id, 'paid_on' => '2026-11-09', 'idempotency_key' => 'web-pay', 'pay' => ['bill-'.$bill->id => '1150.00'],
        ])->assertRedirect();
        $this->assertSame('paid', $bill->fresh()->status);

        // a double submit pays once
        $this->post(route('purchases.payments.store'), [
            'party_id' => $this->supplier->id, 'cash_account_id' => $this->bank->id, 'paid_on' => '2026-11-09', 'idempotency_key' => 'web-pay', 'pay' => ['bill-'.$bill->id => '1150.00'],
        ])->assertRedirect();
        $payment = Payment::query()->forContext($this->ctx)->where('idempotency_key', 'web-pay')->firstOrFail();
        $this->assertSame(1, Payment::query()->forContext($this->ctx)->where('idempotency_key', 'web-pay')->count());

        $this->post(route('purchases.payments.reverse', $payment), ['reason' => 'Paid the wrong account'])->assertRedirect();
        $this->assertSame('open', $bill->fresh()->status);
    }

    public function test_bad_input_comes_back_as_messages_not_errors(): void
    {
        $this->inCompany($this->owner, $this->company);

        $this->post(route('purchases.bills.store'), ['party_id' => $this->supplier->id, 'bill_date' => 'yesterday', 'lines' => [['description' => 'x', 'unit_price' => '1e3']]])->assertSessionHasErrors(['bill_date', 'lines.0.unit_price']);
        $this->from(route('purchases.bills.create'))->post(route('purchases.bills.store'), ['party_id' => $this->supplier->id, 'bill_date' => '2026-11-04', 'lines' => [['description' => 'x', 'unit_price' => '10.00', 'gl_account_id' => $this->gl('1010')->id, 'tax_code' => 'ZA_VAT_STD']]])
            ->assertRedirect(route('purchases.bills.create'))->assertSessionHasErrors('purchases');
        $this->post(route('purchases.payments.store'), ['party_id' => $this->supplier->id, 'cash_account_id' => $this->bank->id, 'paid_on' => '2026-11-09', 'idempotency_key' => 'nothing'])->assertSessionHasErrors('purchases');
    }

    public function test_a_file_can_be_attached_to_a_bill_and_the_same_file_is_not_stored_twice(): void
    {
        $this->inCompany($this->owner, $this->company);
        $path = tempnam(sys_get_temp_dir(), 'fx');
        file_put_contents($path, 'supplier invoice '.uniqid());

        $this->post(route('purchases.bills.attachments.store', $this->bill), ['file' => new UploadedFile($path, 'inv.txt', null, null, true)])->assertSessionHas('status');
        $this->post(route('purchases.bills.attachments.store', $this->bill), ['file' => new UploadedFile($path, 'inv.txt', null, null, true)])->assertSessionDoesntHaveErrors();
        $this->assertSame(1, LedgerContext::run($this->ctx, fn () => $this->bill->attachments()->count()));
        $this->get(route('purchases.documents.index'))->assertOk()->assertDontSee('Bill '.$this->bill->number);
    }

    public function test_receipt_upload_review_and_conversion_through_the_routes(): void
    {
        $this->inCompany($this->owner, $this->company);
        $path = tempnam(sys_get_temp_dir(), 'fx');
        file_put_contents($path, MakesPdfs::text(MakesPdfs::invoiceLines('INV-WEB-9', '2 300.00', '300.00', '2 000.00')));

        $response = $this->post(route('purchases.receipts.store'), ['file' => new UploadedFile($path, 'web.pdf', null, null, true)]);
        $attachment = \App\Models\Shared\Attachment::query()->forContext($this->ctx)->where('original_name', 'web.pdf')->firstOrFail();
        $response->assertRedirect(route('purchases.receipts.show', $attachment));
        $this->get(route('purchases.receipts.show', $attachment))->assertOk()->assertSee('INV-WEB-9')->assertSee('2300.00', false);

        $this->post(route('purchases.receipts.bill', $attachment), [
            'party_id' => $this->supplier->id, 'reference' => 'INV-WEB-9', 'bill_date' => '2026-11-05', 'total' => '2300.00', 'tax' => '300.00', 'document_type' => 'tax_invoice',
            'supplier_vat_number' => '4123456789', 'gl_account_id' => $this->gl('6190')->id, 'tax_code' => 'ZA_VAT_STD',
        ])->assertRedirect();

        $bill = Bill::query()->forContext($this->ctx)->where('supplier_reference', 'INV-WEB-9')->firstOrFail();
        $this->assertSame(['draft', 230_000], [$bill->status, $bill->total_cents]);
        $this->assertSame('reviewed', $attachment->fresh()->extraction_status);
    }

    // ── who may do what ─────────────────────────────────────────────────

    public function test_the_capability_matrix_on_every_write_route(): void
    {
        $people = ['owner' => $this->owner, 'manager' => $this->member('manager'), 'accountant' => $this->member('accountant'), 'bookkeeper' => $this->member('bookkeeper'), 'approver' => $this->member('approver'), 'viewer' => $this->member('viewer')];

        // route => roles that may open/use it (everyone else must get 403)
        $matrix = [
            ['get', fn () => route('purchases.bills.create'), ['owner', 'manager', 'accountant', 'bookkeeper']],
            ['get', fn () => route('purchases.bills.edit', $this->draft), ['owner', 'manager', 'accountant', 'bookkeeper']],
            ['get', fn () => route('purchases.suppliers.create'), ['owner', 'manager', 'accountant', 'bookkeeper']],
            ['get', fn () => route('purchases.suppliers.bank.edit', $this->supplier), ['owner', 'manager', 'accountant', 'bookkeeper']],
            ['get', fn () => route('purchases.claims.create'), ['owner', 'manager', 'accountant', 'bookkeeper']],
            ['get', fn () => route('purchases.payments.create'), ['owner', 'manager', 'accountant', 'bookkeeper']],
            ['get', fn () => route('purchases.runs.create'), ['owner', 'manager', 'accountant', 'bookkeeper']],
            ['get', fn () => route('purchases.recurring.create'), ['owner', 'manager', 'accountant', 'bookkeeper']],
            ['get', fn () => route('purchases.receipts.index'), ['owner', 'manager', 'accountant', 'bookkeeper']],
            ['put', fn () => route('purchases.settings.update'), ['owner', 'manager', 'accountant']],
            ['post', fn () => route('purchases.bills.void', $this->bill), ['owner', 'manager', 'approver']],
            ['post', fn () => route('purchases.payments.reverse', $this->payment), ['owner', 'manager', 'accountant', 'bookkeeper']],
            ['post', fn () => route('purchases.runs.execute', $this->run), ['owner', 'manager', 'accountant', 'bookkeeper']],
            ['post', fn () => route('purchases.bank.approve', $this->pendingBank()), ['owner', 'manager', 'approver']],
        ];

        foreach ($matrix as [$method, $url, $allowed]) {
            foreach ($people as $role => $user) {
                $response = $this->inCompany($user, $this->company)->{$method}($url(), in_array($method, ['post', 'put']) ? ['vat_registered' => 'yes', 'reason' => 'test', 'note' => 'n'] : []);
                if (in_array($role, $allowed, true)) {
                    $this->assertNotSame(403, $response->getStatusCode(), "{$role} should be allowed on {$method} ".$url());
                } else {
                    $response->assertForbidden("{$role} must be refused on {$method} ".$url());
                }
            }
        }
    }

    private function pendingBank(): SupplierBankDetail
    {
        return LedgerContext::run($this->ctx, fn () => SupplierBankDetail::query()->where('status', 'pending')->first())
            ?? app(SupplierBankDetails::class)->request($this->ctx, $this->member('bookkeeper'), $this->supplier, ['bank_name' => 'Capitec', 'account_holder' => 'Acme', 'account_number' => '11112222333'], 'They moved banks', false);
    }

    public function test_everyone_in_the_ledger_can_read_the_lists_and_a_stranger_cannot(): void
    {
        foreach (['bookkeeper', 'approver', 'viewer'] as $role) {
            $user = $this->member($role);
            foreach (['purchases.index', 'purchases.bills.index', 'purchases.suppliers.index', 'purchases.claims.index', 'purchases.payments.index', 'purchases.ap.index', 'purchases.documents.index'] as $name) {
                $this->inCompany($user, $this->company)->get(route($name))->assertOk();
            }
        }

        $stranger = $this->user();
        // a session that claims a company the person is not in falls back to their own personal ledger: none of this company's bills
        $this->actingAs($stranger)->withSession([\App\Support\Ledger\LedgerContextManager::SESSION_KEY => $this->company->id])->get(route('purchases.bills.index'))->assertOk()->assertDontSee($this->bill->number);
        $this->actingAs($stranger)->withSession([\App\Support\Ledger\LedgerContextManager::SESSION_KEY => $this->company->id])->get(route('purchases.bills.show', $this->bill))->assertNotFound();
    }

    public function test_another_ledgers_ids_are_a_404_on_every_route_that_takes_one(): void
    {
        $other = $this->user();
        $theirs = $this->company($other, 'Theirs Ltd');
        $this->inCompany($other, $theirs);

        $routes = [
            ['get', 'purchases.bills.show', $this->bill], ['get', 'purchases.bills.edit', $this->draft], ['put', 'purchases.bills.update', $this->draft], ['delete', 'purchases.bills.destroy', $this->draft],
            ['post', 'purchases.bills.submit', $this->draft], ['post', 'purchases.bills.post', $this->bill], ['post', 'purchases.bills.withdraw', $this->bill], ['post', 'purchases.bills.void', $this->bill],
            ['post', 'purchases.bills.attachments.store', $this->bill], ['get', 'purchases.bills.credit.create', $this->bill], ['post', 'purchases.bills.credit.store', $this->bill], ['post', 'purchases.bills.credit.apply', $this->bill],
            ['get', 'purchases.suppliers.show', $this->supplier], ['get', 'purchases.suppliers.edit', $this->supplier], ['put', 'purchases.suppliers.update', $this->supplier], ['get', 'purchases.suppliers.statement', $this->supplier],
            ['get', 'purchases.suppliers.bank.edit', $this->supplier], ['post', 'purchases.suppliers.bank.store', $this->supplier],
            ['get', 'purchases.payments.show', $this->payment], ['post', 'purchases.payments.reverse', $this->payment], ['post', 'purchases.payments.apply', $this->payment],
            ['get', 'purchases.runs.show', $this->run], ['post', 'purchases.runs.submit', $this->run], ['post', 'purchases.runs.execute', $this->run], ['post', 'purchases.runs.cancel', $this->run], ['get', 'purchases.runs.csv', $this->run],
            ['get', 'purchases.claims.show', $this->claim], ['get', 'purchases.claims.edit', $this->claim], ['put', 'purchases.claims.update', $this->claim], ['delete', 'purchases.claims.destroy', $this->claim],
            ['post', 'purchases.claims.submit', $this->claim], ['post', 'purchases.claims.post', $this->claim], ['post', 'purchases.claims.withdraw', $this->claim], ['post', 'purchases.claims.void', $this->claim],
            ['get', 'purchases.receipts.show', $this->inbox], ['post', 'purchases.receipts.read', $this->inbox], ['post', 'purchases.receipts.bill', $this->inbox], ['post', 'purchases.receipts.claim', $this->inbox], ['post', 'purchases.receipts.reject', $this->inbox],
            ['post', 'purchases.recurring.pause', $this->schedule], ['post', 'purchases.recurring.resume', $this->schedule], ['post', 'purchases.recurring.end', $this->schedule],
            ['post', 'purchases.bank.approve', $this->pendingBank()], ['post', 'purchases.bank.reject', $this->pendingBank()],
        ];

        foreach ($routes as [$method, $name, $model]) {
            $this->{$method}(route($name, $model), ['reason' => 'x', 'note' => 'x'])->assertNotFound("{$method} {$name}");
        }

        // and nothing of mine moved
        $this->assertSame('partially_paid', $this->bill->fresh()->status);
        $this->assertSame('draft', $this->draft->fresh()->status);
        $this->assertSame('recorded', $this->payment->fresh()->status);
    }

    public function test_a_claim_line_of_another_claim_cannot_be_used_to_attach_to_this_one(): void
    {
        $this->inCompany($this->owner, $this->company);
        $second = app(ClaimWorkflow::class)->saveDraft($this->ctx, $this->owner, ['title' => 'Other', 'claim_date' => '2026-11-04', 'lines' => [['spent_on' => '2026-11-04', 'merchant' => 'Spar', 'description' => 'x', 'receipt_type' => 'none', 'gl_account_id' => $this->gl('6190')->id, 'tax_code' => 'ZA_VAT_ZERO', 'gross_cents' => 1_000]]]);
        $line = LedgerContext::run($this->ctx, fn () => $second->lines()->first());
        $path = tempnam(sys_get_temp_dir(), 'fx');
        file_put_contents($path, 'receipt '.uniqid());

        $this->post(route('purchases.claims.lines.attachments.store', [$this->claim, $line]), ['file' => new UploadedFile($path, 'r.txt', null, null, true)])->assertNotFound();
    }

    public function test_supplier_pages_refuse_a_customer_only_party(): void
    {
        $customer = \App\Models\Shared\Party::factory()->forCompany($this->company)->create(['type' => 'customer']);
        $this->inCompany($this->owner, $this->company);

        $this->get(route('purchases.suppliers.show', $customer))->assertNotFound();
        $this->get(route('purchases.suppliers.statement', $customer))->assertNotFound();
    }

    public function test_a_posted_bill_cannot_be_edited_through_the_routes(): void
    {
        $this->inCompany($this->owner, $this->company);

        $this->get(route('purchases.bills.edit', $this->bill))->assertForbidden();
        $this->put(route('purchases.bills.update', $this->bill), ['party_id' => $this->supplier->id, 'bill_date' => '2026-10-01', 'lines' => [['description' => 'x', 'unit_price' => '1.00', 'gl_account_id' => $this->gl('6190')->id, 'tax_code' => 'ZA_VAT_STD']]])->assertForbidden();
        $this->delete(route('purchases.bills.destroy', $this->bill))->assertForbidden();
    }
}
