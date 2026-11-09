<?php

namespace Tests\Feature\Acceptance;

use App\Models\Tax\TaxDeadline;
use App\Services\Purchases\MissingDocuments;
use App\Services\Tax\MissingRecords;
use App\Services\Tax\TaxProfiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/**
 * Owner criterion 21: missing tax documentation is identified. The deadline for the November VAT201 and the EMP201 say, from the real
 * books, which supplier invoices have no file, which suppliers have no VAT number, whether a payroll summary exists and is posted, and
 * where to fix each; attaching the file makes the item go away; nothing is invented when nothing is missing.
 */
class Acceptance21_MissingTaxDocumentsTest extends AcceptanceCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->runMonth(false);
        Carbon::setTestNow('2026-12-01 09:00:00');
        $this->asOwner()->post(route('tax.calendar.generate'))->assertSessionHasNoErrors();
    }

    private function deadline(string $obligation): TaxDeadline
    {
        return TaxDeadline::query()->forContext($this->ledger)->where('obligation', $obligation)->where('period_start', '2026-11-01')->firstOrFail();
    }

    /** @return array<string, array<string, mixed>> keyed by item key */
    private function missing(string $obligation): array
    {
        $items = app(MissingRecords::class)->forDeadline($this->ledger, $this->deadline($obligation), app(TaxProfiles::class)->forLedger($this->ledger));

        return collect($items)->keyBy('key')->all();
    }

    private function file(string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'fx');
        file_put_contents($path, $content.' '.uniqid());

        return new UploadedFile($path, 'supplier-invoice.txt', null, null, true);
    }

    public function test_the_vat_deadline_lists_the_supplier_invoices_with_no_file_and_the_page_says_where_to_fix_them(): void
    {
        $items = $this->missing('vat201');
        $this->assertArrayHasKey('supplier_documents', $items);
        $this->assertSame(3, $items['supplier_documents']['count'], 'input VAT is claimed (box 15) on the Paper Co bill, the toner bill and the fuel claim: none has a file; the credit note is box 12');
        $this->assertSame(5, $items['purchases_missing']['count'], 'Purchases counts the entertainment bill too: it carries no input VAT but has no document either');
        $this->assertStringContainsString('4 of them carry input VAT', $items['purchases_missing']['detail']);

        $page = $this->asOwner()->get(route('tax.calendar.show', $this->deadline('vat201')))->assertOk()->getContent();
        $this->assertStringContainsString('supplier invoices missing the supporting document', $page);
        $this->assertStringContainsString(route('tax.vat.review', ['from' => '2026-11-01', 'to' => '2026-11-30']), html_entity_decode($page));
        $this->asOwner()->get(route('purchases.documents.index'))->assertOk();
    }

    public function test_attaching_the_suppliers_invoice_makes_that_item_go_away_and_the_same_file_twice_changes_nothing(): void
    {
        $before = $this->missing('vat201')['supplier_documents']['count'];
        $beforePurchases = app(MissingDocuments::class)->countForLedger($this->ledger);

        $file = $this->file('tax invoice paper co');
        $this->asOwner()->post(route('purchases.bills.attachments.store', $this->bill1), ['file' => $file])->assertSessionHasNoErrors();
        $this->assertSame($before - 1, $this->missing('vat201')['supplier_documents']['count']);
        $this->assertSame($beforePurchases - 1, app(MissingDocuments::class)->countForLedger($this->ledger));

        $this->asOwner()->post(route('purchases.bills.attachments.store', $this->bill1), ['file' => new UploadedFile($file->getPathname(), 'again.txt', null, null, true)])->assertSessionHasNoErrors();
        $this->assertSame($before - 1, $this->missing('vat201')['supplier_documents']['count'], 'the same bytes again is not a second document');
        $this->assertSame(1, $this->bill1->attachments()->count());
    }

    public function test_a_supplier_with_no_vat_number_is_flagged_for_the_input_vat_claimed_from_it(): void
    {
        $this->assertArrayNotHasKey('supplier_vat_numbers', $this->missing('vat201'), 'the month\'s suppliers all have numbers');
        \App\Models\Shared\Party::query()->forContext($this->ledger)->whereKey($this->paperCo->id)->update(['tax_number' => null]);
        $items = $this->missing('vat201');
        $this->assertArrayHasKey('supplier_vat_numbers', $items);
        $this->assertGreaterThanOrEqual(2, $items['supplier_vat_numbers']['count'], 'Paper Co\'s bill, its credit note and the toner bill');
    }

    public function test_the_emp201_says_when_the_payroll_summary_is_absent_or_not_posted_and_stops_saying_it_when_it_is(): void
    {
        // the month's payroll was captured and posted
        $this->assertArrayNotHasKey('payroll_summary', $this->missing('emp201'));
        $this->assertArrayNotHasKey('payroll_journal', $this->missing('emp201'));

        // an October deadline with no summary at all
        $october = TaxDeadline::query()->forContext($this->ledger)->where('obligation', 'emp201')->where('period_start', '2026-10-01')->first();
        $this->assertNotNull($october, 'the calendar has the October EMP201 deadline');
        $items = collect(app(MissingRecords::class)->forDeadline($this->ledger, $october, app(TaxProfiles::class)->forLedger($this->ledger)))->keyBy('key');
        $this->assertTrue($items->has('payroll_summary'), 'no October payroll summary exists');
        $this->assertStringContainsString('October 2026', $items['payroll_summary']['label']);
    }

    public function test_a_submitted_return_with_no_proof_kept_is_flagged_and_the_flag_names_the_five_year_rule(): void
    {
        $accountant = $this->memberAs('accountant');
        $this->asMember($accountant)->post(route('tax.vat.start'), ['period_start' => '2026-11-01'])->assertSessionHasNoErrors();
        $return = \App\Models\Tax\TaxReturn::query()->forContext($this->ledger)->firstOrFail();
        $this->asMember($accountant)->post(route('tax.returns.prepare', $return))->assertSessionHasNoErrors();
        $this->asMember($accountant)->post(route('tax.returns.submission', $return), ['sars_reference' => 'SARS-Z-9', 'submitted_on' => '2026-12-01'])->assertSessionHasNoErrors();

        $items = $this->missing('vat201');
        $this->assertArrayHasKey('submission_proof', $items);
        $this->assertStringContainsString('five years', $items['submission_proof']['detail']);
    }

    public function test_a_ledger_with_nothing_missing_is_told_so_and_another_ledger_sees_none_of_it(): void
    {
        $other = $this->user();
        $company = $this->company($other, 'Elsewhere');
        $this->enableAccounting(\App\Support\Ledger\LedgerContext::company($company), 'ZAR');
        $this->inCompany($other, $company)->get(route('purchases.documents.index'))->assertOk()->assertDontSee('Paper Co');
        $this->assertSame(0, app(MissingDocuments::class)->countForLedger(\App\Support\Ledger\LedgerContext::company($company)));
    }
}
