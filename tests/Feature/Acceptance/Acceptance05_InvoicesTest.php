<?php

namespace Tests\Feature\Acceptance;

use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalLine;
use App\Models\Sales\SalesDocument;
use App\Models\Sales\SalesProfile;
use App\Models\Shared\Party;

/**
 * Owner criterion 5: invoices are created and issued. Drafted through the form, worked out by the server, issued with a gap-free
 * number, posted to the books, frozen, and only ever voided or credited afterwards.
 */
class Acceptance05_InvoicesTest extends AcceptanceCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIntegration();
    }

    private function body(array $over = []): array
    {
        return $over + [
            'kind' => 'tax_invoice', 'party_id' => $this->acme->id, 'currency' => 'ZAR', 'issue_date' => '2026-11-03', 'client_token' => 'tok-'.uniqid(), 'reference' => 'PO-77',
            'lines' => [
                ['description' => 'Consulting', 'quantity' => '2', 'unit_price' => '1000.00', 'tax_code' => 'ZA_VAT_STD'],
                ['description' => 'Export service', 'quantity' => '1', 'unit_price' => '500.00', 'tax_code' => 'ZA_VAT_ZERO'],
                ['description' => '', 'unit_price' => ''],
            ],
        ];
    }

    private function draftThroughTheForm(array $over = []): SalesDocument
    {
        $response = $this->asOwner()->post(route('sales.documents.store'), $this->body($over));
        $response->assertSessionHasNoErrors();
        $document = SalesDocument::query()->forContext($this->ledger)->latest('id')->firstOrFail();
        $response->assertRedirect(route('sales.documents.show', $document));

        return $document;
    }

    public function test_a_draft_is_created_through_the_form_with_figures_worked_out_by_the_server_and_nothing_posted(): void
    {
        $document = $this->draftThroughTheForm(['total_cents' => 1, 'tax_cents' => 1]);

        $this->assertSame('draft', $document->status);
        $this->assertNull($document->number, 'a draft has no number (D-14)');
        $this->assertSame(['ZAR', 250_000], [$document->currency, (int) $document->total_cents - (int) $document->tax_cents], 'net 2,500.00');
        $this->assertSame(30_000, (int) $document->tax_cents, 'VAT on the standard-rated line only');
        $this->assertSame(280_000, (int) $document->total_cents, '2 x 1,000.00 + 15% + 500.00 zero-rated, whatever the browser sent');
        $this->assertSame(0, JournalEntry::query()->forContext($this->ledger)->where('source_type', 'invoice')->count(), 'a draft posts nothing');
        $this->asOwner()->get(route('sales.documents.show', $document))->assertOk()->assertSee('Draft');
    }

    public function test_a_draft_can_be_changed_and_discarded_without_using_up_a_number(): void
    {
        $first = $this->draftThroughTheForm();
        $this->asOwner()->put(route('sales.documents.update', $first), $this->body(['lines' => [['description' => 'Consulting', 'quantity' => '3', 'unit_price' => '1000.00', 'tax_code' => 'ZA_VAT_STD']]]))->assertSessionHasNoErrors();
        $this->assertSame(345_000, (int) $first->fresh()->total_cents);

        $throwaway = $this->draftThroughTheForm();
        $this->asOwner()->delete(route('sales.documents.discard', $throwaway))->assertSessionHasNoErrors();
        $this->assertNull(SalesDocument::query()->forContext($this->ledger)->find($throwaway->id));

        $this->asOwner()->post(route('sales.documents.issue', $first))->assertSessionHasNoErrors();
        $this->assertSame('INV-00001', $first->fresh()->number, 'the discarded draft left no hole');
    }

    public function test_issuing_gives_the_next_number_posts_receivables_income_and_vat_and_is_safe_to_click_twice(): void
    {
        $document = $this->draftThroughTheForm();
        $this->asOwner()->post(route('sales.documents.issue', $document))->assertSessionHasNoErrors();
        $this->asOwner()->post(route('sales.documents.issue', $document))->assertSessionHasNoErrors(); // the double click

        $document->refresh();
        $this->assertSame('INV-00001', $document->number);
        $this->assertNotSame('draft', $document->status);
        $this->assertNotNull($document->sealed_at, 'issued documents are frozen');

        $entries = JournalEntry::query()->forContext($this->ledger)->where('source_type', 'invoice')->where('source_id', $document->id)->get();
        $this->assertCount(1, $entries, 'issuing twice posts once');
        $lines = JournalLine::query()->where('journal_entry_id', $entries[0]->id)->get();
        $this->assertSame(280_000, (int) $lines->firstWhere('gl_account_id', $this->gl('ar')->id)->debit_cents, 'receivables are debited the gross');
        $this->assertSame(30_000, (int) $lines->firstWhere('gl_account_id', $this->gl('vat_output')->id)->credit_cents, 'VAT output is credited');
        $this->assertSame(250_000, (int) $lines->where('gl_account_id', $this->gl('sales')->id)->sum('credit_cents'), 'income is credited the net');
        $this->assertSame($lines->sum('debit_cents'), $lines->sum('credit_cents'));
        $this->assertBooksVerify();

        $second = $this->draftThroughTheForm();
        $this->asOwner()->post(route('sales.documents.issue', $second));
        $this->assertSame('INV-00002', $second->fresh()->number, 'gap-free');
    }

    public function test_an_issued_invoice_is_a_valid_tax_invoice_prints_and_makes_a_pdf(): void
    {
        $document = $this->draftThroughTheForm();
        $this->asOwner()->post(route('sales.documents.issue', $document));

        $this->asOwner()->get(route('sales.documents.show', $document))->assertOk()->assertSee('INV-00001')->assertSee('Valid tax invoice');
        $this->asOwner()->get(route('sales.documents.print', $document))->assertOk()->assertSee('Tax invoice')->assertSee('Harbour Trading (Pty) Ltd')->assertSee('4123456789')->assertSee('Acme Retail');
        $pdf = $this->asOwner()->get(route('sales.documents.pdf', $document));
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->asOwner()->get(route('sales.invoices.index'))->assertOk()->assertSee('INV-00001');
    }

    /** Defect D-1600: the Sales profile in this ledger does not say VAT; the Tax profile does. The invoice must still carry the VAT number. */
    public function test_the_suppliers_vat_number_on_a_tax_invoice_comes_from_the_tax_profile_when_the_sales_profile_has_none(): void
    {
        $this->assertNull(SalesProfile::query()->forContext($this->ledger)->firstOrFail()->vat_number, 'precondition: the Sales profile holds no VAT number');
        $document = $this->draftThroughTheForm();
        $this->asOwner()->get(route('sales.documents.show', $document))->assertOk()->assertSee('Your VAT number');
        $this->asOwner()->post(route('sales.documents.issue', $document));

        $this->assertSame('4123456789', $document->fresh()->supplier_snapshot['vat_number']);
        $this->assertTrue((bool) $document->fresh()->vat_registered);
    }

    public function test_an_issued_invoice_cannot_be_edited_only_voided_with_a_reason_which_reverses_the_posting_and_keeps_the_number(): void
    {
        $document = $this->draftThroughTheForm();
        $this->asOwner()->post(route('sales.documents.issue', $document));
        $total = (int) $document->fresh()->total_cents;

        $this->asOwner()->put(route('sales.documents.update', $document), $this->body(['lines' => [['description' => 'Changed', 'quantity' => '1', 'unit_price' => '1.00', 'tax_code' => 'ZA_VAT_STD']]]));
        $this->assertSame($total, (int) $document->fresh()->total_cents, 'the frozen document did not change');

        $this->asOwner()->post(route('sales.documents.void', $document), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->asOwner()->post(route('sales.documents.void', $document), ['reason' => 'Raised to the wrong customer'])->assertSessionHasNoErrors();
        $this->assertSame('void', $document->fresh()->status);
        $this->assertSame('INV-00001', $document->fresh()->number, 'a void keeps its number on record');
        $this->assertSame(0, $this->balance('ar'), 'the posting was reversed');
        $this->assertSame(0, $this->balance('vat_output'));
        $this->assertBooksVerify();

        $next = $this->draftThroughTheForm();
        $this->asOwner()->post(route('sales.documents.issue', $next));
        $this->assertSame('INV-00002', $next->fresh()->number, 'numbers are never reused');
    }

    public function test_an_invoice_that_fails_the_tax_invoice_rules_is_refused_for_a_vat_vendor(): void
    {
        $noAddress = Party::create($this->ledger->ownerAttributes() + ['type' => 'customer', 'name' => 'Walk-in Trader', 'status' => 'active', 'payment_terms_days' => 30]);
        $document = $this->draftThroughTheForm(['party_id' => $noAddress->id, 'lines' => [['description' => 'Big job', 'quantity' => '1', 'unit_price' => '8000.00', 'tax_code' => 'ZA_VAT_STD']]]);

        $this->asOwner()->post(route('sales.documents.issue', $document))->assertSessionHasErrors('sales');
        $document->refresh();
        $this->assertSame('draft', $document->status);
        $this->assertNull($document->number, 'a refused issue consumes no number');
        $this->assertSame(0, JournalEntry::query()->forContext($this->ledger)->where('source_type', 'invoice')->count());
    }

    public function test_invalid_input_and_foreign_customers_are_refused_and_viewers_cannot_issue(): void
    {
        $this->asOwner()->post(route('sales.documents.store'), $this->body(['issue_date' => 'tomorrow-ish']))->assertSessionHasErrors('issue_date');
        $this->asOwner()->post(route('sales.documents.store'), $this->body(['lines' => [['description' => 'x', 'quantity' => '1', 'unit_price' => '1e3', 'tax_code' => 'ZA_VAT_STD']]]))->assertSessionHasErrors();

        $foreign = Party::factory()->forCompany($this->company($this->user(), 'Elsewhere'))->create(['type' => 'customer']);
        $this->asOwner()->post(route('sales.documents.store'), $this->body(['party_id' => $foreign->id]))->assertSessionHasErrors();

        $document = $this->draftThroughTheForm();
        $viewer = $this->memberAs('viewer');
        $this->asMember($viewer)->post(route('sales.documents.issue', $document))->assertForbidden();
        $this->asMember($viewer)->post(route('sales.documents.store'), $this->body())->assertForbidden();
        $this->assertSame('draft', $document->fresh()->status);
    }
}
