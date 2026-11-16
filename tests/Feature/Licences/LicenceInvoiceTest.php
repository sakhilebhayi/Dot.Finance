<?php

namespace Tests\Feature\Licences;

use App\Models\Licences\LicenceBatch;
use App\Models\Licences\LicenceCreditNote;
use App\Models\Licences\LicenceOutboxEvent;
use App\Models\Licences\LicencePayment;
use App\Models\Sales\SalesDocument;
use App\Models\Shared\Party;
use App\Services\Licences\LicenceInvoicer;
use App\Services\Licences\LicenceRefunds;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsLicences;
use Tests\Concerns\BuildsSales;
use Tests\TestCase;

class LicenceInvoiceTest extends TestCase
{
    use BuildsLicences;
    use BuildsSales;
    use RefreshDatabase;

    private function boot(bool $vat = true): void
    {
        $this->setUpSales($vat);
        $this->setUpLicences();
        $this->configureWebhook();
        $this->configurePush();
        config(['licences.seller.team_uuid' => $this->company->infodot_team_uuid, 'licences.seller.user_email' => $this->owner->email]);
        Http::fake(['infodot.test/*' => Http::response(['status' => 'applied'], 200)]);
    }

    private function paid(): array
    {
        [$batch, $ref] = $this->checkedOut();
        $this->paystack->pay($ref, ['plan_code' => 'PLN_fake1'], 29700);
        $this->webhook($this->chargeSuccess($ref))->assertOk();

        return [$batch->fresh(), $ref];
    }

    /** A credit note is always a full tax document, so the buyer needs an address in the seller's books (the contract does not carry one). */
    private function giveBuyerAnAddress(string $ref): void
    {
        $partyId = $this->invoiceOf($ref)->party_id;
        Party::query()->forContext($this->ledger)->findOrFail($partyId)->update(['billing_address' => ['line1' => '5 Short Road', 'city' => 'Durban', 'country' => 'South Africa']]);
    }

    private function invoiceOf(string $ref): SalesDocument
    {
        $p = LicencePayment::where('paystack_reference', $ref)->sole();
        $this->assertSame('issued', $p->invoice_status, (string) $p->invoice_error);

        return LedgerContext::run($this->ledger, fn () => SalesDocument::query()->forContext($this->ledger)->findOrFail($p->sales_document_id));
    }

    public function test_a_vat_registered_seller_gets_a_tax_invoice_whose_total_is_what_was_paid(): void
    {
        $this->boot(true);
        [$batch, $ref] = $this->paid();

        $invoice = $this->invoiceOf($ref);

        $this->assertSame('tax_invoice', $invoice->kind);
        $this->assertSame(29700, (int) $invoice->total_cents);
        $this->assertSame(3874, (int) $invoice->tax_cents); // 15% extracted from 29 700 (VAT-inclusive)
        $this->assertSame($ref, $invoice->reference);
        $this->assertNotNull($invoice->number);
        $this->assertSame('infodot-team:'.$batch->team_uuid, Party::query()->forContext($this->ledger)->findOrFail($invoice->party_id)->external_ref);
        $this->assertBooksBalance();
    }

    public function test_a_seller_that_is_not_vat_registered_gets_an_invoice_without_vat(): void
    {
        $this->boot(false);
        [, $ref] = $this->paid();

        $invoice = $this->invoiceOf($ref);

        $this->assertSame(0, (int) $invoice->tax_cents);
        $this->assertSame(29700, (int) $invoice->total_cents);
    }

    public function test_the_same_payment_never_makes_two_invoices_and_a_renewal_makes_its_own(): void
    {
        $this->boot();
        [$batch, $ref] = $this->paid();
        $payment = LicencePayment::where('paystack_reference', $ref)->sole();

        app(LicenceInvoicer::class)->invoice($payment);
        app(LicenceInvoicer::class)->invoice($payment);
        $this->paystack->pay('renew_1', ['plan_code' => 'PLN_fake1', 'customer_code' => 'CUS_fake'], 29700);
        $this->webhook($this->chargeSuccess('renew_1', 29700, ['plan' => ['plan_code' => 'PLN_fake1']]))->assertOk();

        $this->assertSame(2, SalesDocument::query()->withoutGlobalScopes()->where('kind', 'tax_invoice')->count());
        $this->assertNotSame($this->invoiceOf($ref)->id, $this->invoiceOf('renew_1')->id);
    }

    public function test_an_unconfigured_seller_still_records_and_confirms_and_the_invoice_can_be_made_later(): void
    {
        $this->boot();
        config(['licences.seller.team_uuid' => '']);
        [$batch, $ref] = $this->paid();

        $payment = LicencePayment::where('paystack_reference', $ref)->sole();
        $this->assertSame('skipped', $payment->invoice_status);
        $this->assertSame('active', $batch->status);
        $this->assertSame('sent', LicenceOutboxEvent::where('type', 'paid')->sole()->status);

        config(['licences.seller.team_uuid' => $this->company->infodot_team_uuid]);
        $this->artisan('licences:invoices')->assertExitCode(0);
        $this->assertSame('issued', $payment->fresh()->invoice_status);
    }

    public function test_a_processed_refund_becomes_a_credit_note_that_zeroes_the_invoice(): void
    {
        $this->boot();
        [$batch, $ref] = $this->paid();
        $invoice = $this->invoiceOf($ref);
        $this->giveBuyerAnAddress($ref);

        $this->webhook(['event' => 'refund.processed', 'data' => ['id' => 700, 'transaction_reference' => $ref, 'amount' => 29700, 'status' => 'processed']])->assertOk();

        $note = LicenceCreditNote::sole();
        $this->assertSame('issued', $note->status, (string) $note->error);
        $credit = LedgerContext::run($this->ledger, fn () => SalesDocument::query()->forContext($this->ledger)->findOrFail($note->sales_document_id));
        $this->assertSame('credit_note', $credit->kind);
        $this->assertSame($invoice->id, (int) $credit->parent_id);
        $this->assertSame(29700, (int) $credit->total_cents);
        $this->assertSame('refunded', $batch->fresh()->status);
        $this->assertBooksBalance();

        // replay of the same refund: still one credit note
        $this->webhook(['event' => 'refund.processed', 'data' => ['id' => 700, 'transaction_reference' => $ref, 'amount' => 29700, 'status' => 'processed']])->assertOk();
        $this->assertSame(1, LicenceCreditNote::count());
    }

    public function test_a_credit_note_the_books_refuse_stays_visible_and_is_made_once_the_address_is_added(): void
    {
        $this->boot();
        [, $ref] = $this->paid();
        $this->invoiceOf($ref);

        $this->webhook(['event' => 'refund.processed', 'data' => ['id' => 702, 'transaction_reference' => $ref, 'amount' => 29700, 'status' => 'processed']])->assertOk();
        $note = LicenceCreditNote::sole();
        $this->assertSame('failed', $note->status);
        $this->assertStringContainsString('address', (string) $note->error);
        $this->assertSame('sent', LicenceOutboxEvent::where('type', 'refunded')->sole()->status, 'InfoDot is told regardless of the books');
        $this->artisan('licences:invoices')->assertExitCode(1);

        $this->giveBuyerAnAddress($ref);
        $this->artisan('licences:invoices')->assertExitCode(0);
        $this->assertSame('issued', $note->fresh()->status);
    }

    public function test_a_partial_refund_credits_only_that_amount(): void
    {
        $this->boot();
        [, $ref] = $this->paid();
        $this->invoiceOf($ref);
        $this->giveBuyerAnAddress($ref);

        $this->webhook(['event' => 'refund.processed', 'data' => ['id' => 701, 'transaction_reference' => $ref, 'amount' => 10000, 'status' => 'processed']])->assertOk();

        $note = LicenceCreditNote::sole();
        $this->assertSame('issued', $note->status, (string) $note->error);
        $credit = SalesDocument::query()->withoutGlobalScopes()->findOrFail($note->sales_document_id);
        $this->assertSame(10000, (int) $credit->total_cents);
        $this->assertSame('partially_refunded', LicencePayment::sole()->status);
        $this->assertSame(10000, LicenceOutboxEvent::where('type', 'refunded')->sole()->body()['amount_minor']);
    }

    public function test_refund_requests_obey_the_first_purchase_window_and_amount_limits(): void
    {
        $this->boot();
        [$batch, $ref] = $this->paid();
        $refunds = app(LicenceRefunds::class);

        $this->assertSame('pending', $refunds->request($batch, null, 5000));
        $this->assertSame(1, $this->paystack->called('refund'));

        try {
            $refunds->request($batch, null, 99999999);
            $this->fail('over-refund allowed');
        } catch (\RuntimeException) {
        }

        $this->travel(8)->days();
        $this->expectException(\RuntimeException::class);
        $refunds->request(LicenceBatch::findOrFail($batch->id));
    }

    public function test_no_licence_table_is_read_across_ledgers_the_customer_lives_in_the_sellers_ledger_only(): void
    {
        $this->boot();
        [$batch] = $this->paid();
        $other = $this->company($this->user(), 'Another Co');

        $this->assertSame(0, Party::query()->forContext(LedgerContext::company($other))->where('external_ref', 'infodot-team:'.$batch->team_uuid)->count());
    }
}
