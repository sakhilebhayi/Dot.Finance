<?php

namespace Tests\Feature\Tax;

use App\Models\Accounting\JournalLine;
use App\Models\Tax\TaxCorrespondence;
use App\Models\Tax\TaxDeadline;
use App\Models\Tax\TaxDocument;
use App\Models\Tax\TaxPayrollLine;
use App\Models\Tax\TaxPayrollRun;
use App\Models\Tax\TaxProfile;
use App\Models\Tax\TaxReturn;
use App\Services\Tax\CsvSafe;
use App\Services\Tax\DeadlineGenerator;
use App\Services\Tax\Exceptions\TaxException;
use App\Services\Tax\MissingRecords;
use App\Services\Tax\TaxDocuments;
use App\Services\Tax\TaxExplainer;
use App\Services\Tax\TaxReturnService;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTaxFixtures;
use Tests\TestCase;
use ZipArchive;

/** The tax routes as each role meets them: who may prepare, record, export and see payroll detail; what an export says; no leak across ledgers. */
class TaxHttpTest extends TestCase
{
    use BuildsTaxFixtures;
    use RefreshDatabase;

    private TaxReturn $vat;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->taxLedger();
        $this->travelTo(CarbonImmutable::parse('2026-11-09 10:00'));
        $profile = $this->vatProfile();
        $this->sale('2026-09-10', 100_000);
        $this->purchase('2026-09-15', 40_000);
        LedgerContext::run($this->tax, fn () => app(DeadlineGenerator::class)->generate($this->tax, $profile, CarbonImmutable::today()));
        $this->vat = LedgerContext::run($this->tax, fn () => app(TaxReturnService::class)->start($this->tax, 'vat201', CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-10-31'), 'Sep to Oct 2026', $profile, $this->people['owner']));
    }

    private function as(string $role): static
    {
        return $this->inCompany($this->people[$role], $this->acme);
    }

    private function prepared(): TaxReturn
    {
        return LedgerContext::run($this->tax, fn () => app(TaxReturnService::class)->prepare($this->tax, $this->vat, TaxProfile::query()->forContext($this->tax)->first(), $this->people['owner']));
    }

    // ── capabilities ────────────────────────────────────────────────────

    public function test_everyone_in_the_ledger_can_read_the_tax_pages(): void
    {
        foreach (['owner', 'manager', 'accountant', 'bookkeeper', 'approver', 'viewer'] as $role) {
            foreach ([route('tax.index'), route('tax.calendar.index'), route('tax.vat.index'), route('tax.returns.show', $this->vat), route('tax.income.index'), route('tax.documents.index')] as $url) {
                $this->as($role)->get($url)->assertOk();
            }
        }
    }

    public function test_preparing_needs_the_prepare_capability(): void
    {
        foreach (['bookkeeper', 'approver', 'viewer'] as $role) {
            $this->as($role)->post(route('tax.returns.prepare', $this->vat))->assertForbidden();
            $this->as($role)->post(route('tax.returns.adjust', $this->vat), ['box_code' => '17', 'kind' => 'bad_debt', 'direction' => 'increase', 'amount' => '10.00', 'reason' => 'A reason here'])->assertForbidden();
        }
        $this->assertSame('draft', $this->vat->fresh()->status);

        $this->as('accountant')->post(route('tax.returns.prepare', $this->vat))->assertRedirect();
        $this->assertSame('prepared', $this->vat->fresh()->status);
    }

    public function test_an_adjustment_through_the_form_needs_a_reason_and_is_recorded_with_who_entered_it(): void
    {
        $this->as('accountant')->post(route('tax.returns.adjust', $this->vat), ['box_code' => '17', 'kind' => 'bad_debt', 'direction' => 'increase', 'amount' => '45.50', 'reason' => 'x'])->assertSessionHasErrors('reason');
        $this->as('accountant')->post(route('tax.returns.adjust', $this->vat), ['box_code' => '17', 'kind' => 'bad_debt', 'direction' => 'increase', 'amount' => '45.50', 'reason' => 'Invoice 114 written off after liquidation'])->assertSessionHasNoErrors();
        $a = TaxReturn::query()->forContext($this->tax)->first()->adjustments()->get();
        $this->assertCount(1, $a);
        $this->assertSame(4_550, $a[0]->amount_cents);
        $this->as('accountant')->post(route('tax.returns.adjust', $this->vat), ['box_code' => '13', 'kind' => 'other', 'direction' => 'increase', 'amount' => '1.00', 'reason' => 'Trying a calculated field'])->assertSessionHasErrors('adjustment');
        $this->as('accountant')->post(route('tax.returns.adjust', $this->vat), ['box_code' => '15', 'kind' => 'other', 'direction' => 'increase', 'amount' => '1e3', 'reason' => 'Exponent notation'])->assertSessionHasErrors('amount');
    }

    public function test_recording_a_submission_or_payment_needs_the_record_capability_and_is_not_a_filing(): void
    {
        $this->prepared();
        $body = ['sars_reference' => 'SARS-0001', 'submitted_on' => '2026-11-05'];

        foreach (['bookkeeper', 'approver', 'viewer'] as $role) {
            $this->as($role)->post(route('tax.returns.submission', $this->vat), $body)->assertForbidden();
        }
        $this->assertSame('prepared', $this->vat->fresh()->status);

        $this->as('accountant')->post(route('tax.returns.submission', $this->vat), $body)->assertRedirect();
        $this->assertSame('submitted', $this->vat->fresh()->status);
        $this->as('bookkeeper')->post(route('tax.returns.payment', $this->vat), ['amount' => '90.00', 'paid_on' => '2026-11-08'])->assertForbidden();
        $this->as('accountant')->post(route('tax.returns.payment', $this->vat), ['amount' => '90.00', 'paid_on' => '2026-11-08', 'reference' => 'PRN 1'])->assertRedirect();
        $this->assertSame('paid', $this->vat->fresh()->status);

        $page = $this->as('owner')->get(route('tax.returns.show', $this->vat))->assertOk()->getContent();
        $this->assertStringContainsString('Dot.Finance did not submit it', $page);
        $this->assertStringNotContainsString('Submitted to SARS', $page);
    }

    public function test_the_tax_profile_needs_the_manage_tax_capability_and_validates_its_input(): void
    {
        $data = ['jurisdiction' => 'ZA', 'legal_entity' => 'company', 'tax_regime' => 'standard', 'vat_registered' => '1', 'vat_number' => '4123456789', 'vat_category' => 'B', 'vat_basis' => 'invoice', 'financial_year_end_month' => 2, 'accounting_basis' => 'accrual'];

        $this->as('bookkeeper')->put(route('tax.profile.update'), $data)->assertForbidden();
        $this->as('viewer')->put(route('tax.profile.update'), $data)->assertForbidden();
        $this->as('accountant')->put(route('tax.profile.update'), $data)->assertRedirect(route('tax.obligations'));
        $this->as('accountant')->put(route('tax.profile.update'), ['vat_number' => 'abc'] + $data)->assertSessionHasErrors('vat_number');
        $this->as('accountant')->put(route('tax.profile.update'), ['jurisdiction' => 'GB'] + $data)->assertSessionHasErrors('jurisdiction');
        $this->as('accountant')->put(route('tax.profile.update'), ['vat_category' => null] + $data)->assertSessionHasErrors('profile');
        $this->as('accountant')->put(route('tax.profile.update'), ['legal_entity' => 'trust', 'tax_regime' => 'sbc'] + $data)->assertSessionHasErrors('profile');
    }

    public function test_per_employee_payroll_detail_is_only_for_those_who_may_see_it(): void
    {
        $run = TaxPayrollRun::query()->create($this->tax->ownerAttributes() + ['period_month' => '2026-10-01', 'source' => 'hr_import', 'status' => 'draft', 'gross_pay_cents' => 3_000_000, 'paye_cents' => 400_000, 'employee_count' => 1]);
        TaxPayrollLine::query()->create($this->tax->ownerAttributes() + ['tax_payroll_run_id' => $run->id, 'employee_ref' => 'E-77', 'employee_name' => 'Zodwa Secretname', 'gross_cents' => 3_000_000, 'paye_cents' => 400_000]);

        $this->as('bookkeeper')->get(route('tax.payroll.show', $run))->assertOk()->assertDontSee('Zodwa Secretname')->assertDontSee('E-77')->assertSee('cannot see payroll detail');
        $this->as('viewer')->get(route('tax.payroll.show', $run))->assertDontSee('Zodwa Secretname');
        $this->as('accountant')->get(route('tax.payroll.show', $run))->assertOk()->assertSee('Zodwa Secretname');

        $this->as('bookkeeper')->post(route('tax.payroll.store'), ['month' => '2026-09', 'gross_pay' => '100.00', 'paye' => '10.00', 'employee_count' => 1])->assertForbidden();
        $this->as('accountant')->post(route('tax.payroll.post', $run), ['confirm' => '1'])->assertSessionHasNoErrors();
    }

    public function test_exports_need_the_export_capability(): void
    {
        $this->as('viewer')->get(route('tax.returns.csv', $this->vat))->assertForbidden();
        $this->as('approver')->get(route('tax.returns.workpaper', $this->vat))->assertForbidden();
        $this->as('viewer')->post(route('tax.pack.download'), ['from' => '2026-03-01', 'to' => '2026-11-09'])->assertForbidden();
        $this->as('bookkeeper')->get(route('tax.returns.csv', $this->vat))->assertOk();
    }

    // ── what the exports say ────────────────────────────────────────────

    public function test_every_working_paper_and_csv_carries_the_banner_the_period_the_basis_and_the_rule_versions(): void
    {
        foreach (['draft', 'prepared', 'submitted'] as $stage) {
            if ($stage === 'prepared') {
                $this->prepared();
            }
            if ($stage === 'submitted') {
                LedgerContext::run($this->tax, fn () => app(TaxReturnService::class)->recordSubmission($this->tax, $this->vat->fresh(), 'REF-9', CarbonImmutable::parse('2026-11-05'), $this->people['owner']));
            }

            $csv = $this->as('owner')->get(route('tax.returns.csv', $this->vat))->assertOk()->getContent();
            $html = $this->as('owner')->get(route('tax.returns.workpaper', $this->vat))->assertOk()->getContent();

            foreach (['csv' => $csv, 'html' => $html] as $kind => $text) {
                $this->assertStringContainsString('Dot.Finance', $text, "{$stage} {$kind}");
                $this->assertStringContainsString('2026-09-01', $text, "{$stage} {$kind} names the period");
                $this->assertStringContainsString('invoice', $text, "{$stage} {$kind} names the basis");
                $this->assertStringContainsString('tax_rates#', $text, "{$stage} {$kind} names the rule versions used");
                $this->assertStringContainsString('information, not professional tax advice', $text, "{$stage} {$kind}");
            }
            $banner = match ($stage) {
                'draft' => 'DRAFT, NOT PREPARED, NOT SUBMITTED',
                'prepared' => 'PREPARED, NOT SUBMITTED',
                'submitted' => 'RECORDED AS SUBMITTED BY A PERSON',
            };
            $this->assertStringContainsString($banner, $csv);
            $this->assertStringContainsString($banner, $html);
        }
    }

    public function test_the_csv_lists_every_field_with_the_lines_behind_it_and_is_formula_safe(): void
    {
        $this->sale('2026-09-12', 10_000, 'ZA_VAT_ZERO');
        $csv = $this->as('owner')->get(route('tax.returns.csv', $this->vat))->getContent();
        foreach (['1', '1A', '2', '4', '13', '15', '19', '20'] as $f) {
            $this->assertMatchesRegularExpression('/^'.preg_quote($f, '/').',/m', $csv, "field {$f} present");
        }
        $this->assertStringContainsString('Drill-down: the lines behind each field', $csv);
        $this->assertStringContainsString('JE-', $csv);

        $line = CsvSafe::cell('=HYPERLINK("http://evil")');
        $this->assertSame("'=HYPERLINK(\"http://evil\")", $line);
        $this->assertSame('-123.45', CsvSafe::cell('-123.45'));
    }

    public function test_the_accountant_pack_holds_the_books_the_working_papers_and_the_rule_versions(): void
    {
        $this->prepared();
        $run = TaxPayrollRun::query()->create($this->tax->ownerAttributes() + ['period_month' => '2026-10-01', 'source' => 'manual', 'status' => 'draft', 'gross_pay_cents' => 3_000_000, 'paye_cents' => 400_000, 'employee_count' => 1]);
        TaxPayrollLine::query()->create($this->tax->ownerAttributes() + ['tax_payroll_run_id' => $run->id, 'employee_ref' => 'E-77', 'employee_name' => 'Zodwa Secretname', 'gross_cents' => 3_000_000]);

        $response = $this->as('accountant')->post(route('tax.pack.download'), ['from' => '2026-03-01', 'to' => '2026-11-09']);
        $response->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        foreach (['README.txt', 'trial_balance.csv', 'general_ledger.csv', 'rule_versions.csv', 'return_adjustments.csv', 'income_tax_adjustments.csv', 'income_tax_estimates.csv', 'payroll_summaries.csv', 'document_index.csv', 'correspondence.csv', 'deadlines.csv'] as $f) {
            $this->assertContains($f, $names);
        }
        $this->assertTrue(collect($names)->contains(fn ($n) => str_starts_with($n, 'vat201/')));
        $this->assertStringContainsString('PREPARED, NOT SUBMITTED', $zip->getFromName('README.txt'));
        $this->assertStringContainsString('JE-', $zip->getFromName('general_ledger.csv'));
        $this->assertStringContainsString('tax_rates#', $zip->getFromName('rule_versions.csv'));
        $this->assertNotContains('payroll_employee_detail.csv', $names, 'no per-employee detail unless asked for');
        foreach ($names as $n) {
            $this->assertStringNotContainsString('Zodwa', $zip->getFromName($n), "{$n} must not leak the employee name");
        }
        $zip->close();

        // with the capability and the request, the detail file exists
        $response2 = $this->as('accountant')->post(route('tax.pack.download'), ['from' => '2026-03-01', 'to' => '2026-11-09', 'payroll_detail' => '1']);
        $zip2 = new ZipArchive;
        $zip2->open($response2->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString('Zodwa Secretname', $zip2->getFromName('payroll_employee_detail.csv'));
        $zip2->close();
    }

    // ── isolation ───────────────────────────────────────────────────────

    public function test_another_ledgers_returns_documents_and_numbers_never_appear_or_open(): void
    {
        $other = $this->company($this->people['owner'], 'Other (Pty) Ltd');
        $otherCtx = $this->companyContext($other);
        $this->enableAccounting($otherCtx, 'ZAR');
        $theirProfile = $this->vatProfile([], $otherCtx);
        $this->sale('2026-09-10', 777_700, 'ZA_VAT_STD', null, $otherCtx);
        $theirs = LedgerContext::run($otherCtx, fn () => app(TaxReturnService::class)->start($otherCtx, 'vat201', CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-10-31'), 'Theirs Sep to Oct', $theirProfile));
        $doc = TaxDocument::query()->create($otherCtx->ownerAttributes() + ['category' => 'other', 'title' => 'Their secret SARS letter', 'retention_until' => '2031-01-01', 'retention_basis' => 'x']);
        TaxCorrespondence::query()->create($otherCtx->ownerAttributes() + ['direction' => 'received', 'channel' => 'letter', 'subject' => 'Their audit notice', 'occurred_on' => '2026-10-01']);

        foreach ([route('tax.returns.show', $theirs), route('tax.returns.csv', $theirs), route('tax.returns.workpaper', $theirs), route('tax.documents.file', $doc)] as $url) {
            $this->as('owner')->get($url)->assertNotFound();
        }
        $this->as('owner')->post(route('tax.returns.prepare', $theirs))->assertNotFound();
        $this->as('owner')->post(route('tax.returns.submission', $theirs), ['sars_reference' => 'X', 'submitted_on' => '2026-11-05'])->assertNotFound();
        $this->assertSame('draft', $theirs->fresh()->status);

        foreach ([route('tax.documents.index'), route('tax.vat.index'), route('tax.index'), route('tax.vat.review')] as $url) {
            $html = $this->as('owner')->get($url)->getContent();
            $this->assertStringNotContainsString('Their secret SARS letter', $html);
            $this->assertStringNotContainsString('Their audit notice', $html);
            $this->assertStringNotContainsString('7,777.00', $html);
        }
        // and the VAT figures in this ledger ignore their sale
        $this->assertSame(15_000, LedgerContext::run($this->tax, fn () => app(TaxReturnService::class)->compute($this->tax, $this->vat))->amount('4'));
    }

    public function test_a_review_decision_cannot_be_made_on_another_ledgers_line(): void
    {
        $other = $this->company($this->people['owner'], 'Other (Pty) Ltd');
        $otherCtx = $this->companyContext($other);
        $this->enableAccounting($otherCtx, 'ZAR');
        $e = $this->sale('2026-09-10', 100_000, 'ZA_VAT_STD', 0, $otherCtx);
        $lineId = JournalLine::withoutGlobalScopes()->where('journal_entry_id', $e->id)->value('id');

        $this->as('accountant')->post(route('tax.vat.review.resolve'), ['journal_line_id' => $lineId, 'issue_code' => 'standard_without_vat', 'resolution' => 'accepted', 'note' => 'Trying another ledger'])->assertNotFound();
        $this->assertSame(0, \DB::table('tax_review_resolutions')->count());
    }

    // ── vault, missing records, explanations ────────────────────────────

    public function test_a_vault_record_has_a_retention_date_a_legal_hold_and_is_never_deleted(): void
    {
        $file = UploadedFile::fake()->createWithContent('proof.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        $this->as('accountant')->post(route('tax.documents.store'), ['category' => 'vat_return_proof', 'title' => 'VAT201 Sep-Oct acknowledgement', 'document_date' => '2026-11-05', 'file' => $file])->assertSessionHasNoErrors();
        $doc = TaxDocument::query()->forContext($this->tax)->firstOrFail();

        $this->assertSame('2031-11-09', $doc->retention_until->toDateString(), 'five years from the later of the document date and the day filed here');
        $this->assertNotNull($doc->attachment_id);
        $this->assertStringContainsString('section 29', $doc->retention_basis);

        $docs = app(TaxDocuments::class);
        try {
            LedgerContext::run($this->tax, fn () => $docs->archive($this->tax, $doc, $this->people['owner']));
            $this->fail('archived before its date');
        } catch (TaxException $e) {
            $this->assertStringContainsString('must be kept until', $e->getMessage());
        }

        $this->travelTo(CarbonImmutable::parse('2032-01-01'));
        LedgerContext::run($this->tax, fn () => $docs->setLegalHold($this->tax, $doc, true, $this->people['owner']));
        try {
            LedgerContext::run($this->tax, fn () => $docs->archive($this->tax, $doc->fresh(), $this->people['owner']));
            $this->fail('archived under hold');
        } catch (TaxException $e) {
            $this->assertStringContainsString('legal hold', $e->getMessage());
        }
        LedgerContext::run($this->tax, fn () => $docs->setLegalHold($this->tax, $doc->fresh(), false, $this->people['owner']));
        LedgerContext::run($this->tax, fn () => $docs->archive($this->tax, $doc->fresh(), $this->people['owner']));
        $this->assertNotNull($doc->fresh()->archived_at);

        $this->expectException(\LogicException::class);
        $doc->fresh()->delete();
    }

    public function test_the_vault_refuses_a_file_that_is_not_a_document_and_a_viewer_cannot_file_anything(): void
    {
        $this->as('viewer')->post(route('tax.documents.store'), ['category' => 'other', 'title' => 'x'])->assertForbidden();
        $exe = UploadedFile::fake()->createWithContent('run.exe', "MZ\x90\x00 not a document");
        $this->as('accountant')->post(route('tax.documents.store'), ['category' => 'other', 'title' => 'Bad file', 'file' => $exe])->assertSessionHasErrors('document');
        $this->assertSame(0, TaxDocument::query()->forContext($this->tax)->count());
    }

    public function test_missing_supporting_documents_are_counted_from_the_books(): void
    {
        $deadline = TaxDeadline::query()->forContext($this->tax)->where('obligation', 'vat201')->where('period_end', '2026-10-31')->firstOrFail();
        $items = LedgerContext::run($this->tax, fn () => app(MissingRecords::class)->forDeadline($this->tax, $deadline, TaxProfile::query()->forContext($this->tax)->first()));
        $labels = implode(' | ', array_column($items, 'label'));
        $this->assertStringContainsString('1 supplier invoice missing the supporting document', $labels);
        $this->assertStringContainsString('without a VAT number', $labels);
    }

    public function test_the_explainer_says_what_an_entry_did_and_why_a_figure_changed(): void
    {
        $e = $this->purchase('2026-09-18', 20_000);
        $text = implode(' ', LedgerContext::run($this->tax, fn () => app(TaxExplainer::class)->transaction($this->tax, $e->id))['paragraphs']);
        $this->assertStringContainsString('purchase of ZAR 200.00 before VAT', $text);
        $this->assertStringContainsString('15 percent', $text);
        $this->assertStringContainsString('field 15', $text);

        $ent = $this->purchase('2026-09-19', 10_000, 'ZA_VAT_DENIED_ENTERTAINMENT', 0, null, 'Advertising and marketing');
        $this->assertStringContainsString('not claimable', implode(' ', LedgerContext::run($this->tax, fn () => app(TaxExplainer::class)->transaction($this->tax, $ent->id))['paragraphs']));

        // change: an entry posted after the first look
        $first = CarbonImmutable::now();
        $this->travel(1)->hours();
        $this->sale('2026-09-25', 200_000);
        $change = LedgerContext::run($this->tax, fn () => app(TaxExplainer::class)->change($this->tax, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-10-31'), $first->addMinute(), CarbonImmutable::now()->addMinute()));
        $boxes = array_column($change['rows'], 'delta_cents', 'box');
        $this->assertSame(30_000, $boxes['4'], 'output VAT up by 15% of 2,000.00');
        $this->assertCount(3, array_filter($change['new_lines'], fn ($l) => in_array($l['box'], ['1', '4'], true)), 'the sale line and its VAT line in field 1, the VAT line in field 4');
        $this->assertStringContainsString('information, not professional tax advice', $change['footer']);

        $none = LedgerContext::run($this->tax, fn () => app(TaxExplainer::class)->transaction($this->tax, 999_999));
        $this->assertFalse($none['found']);
    }

    public function test_the_explain_page_answers_from_real_rows(): void
    {
        $e = $this->purchase('2026-09-18', 20_000);
        $this->as('viewer')->get(route('tax.explain.index', ['mode' => 'entry', 'entry' => $e->id]))->assertOk()->assertSee('purchase of ZAR 200.00 before VAT')->assertSee('Calculated');
        $this->as('viewer')->get(route('tax.explain.index', ['mode' => 'entry', 'entry' => 424242]))->assertOk()->assertSee('no such entry');
    }

    public function test_the_tax_pages_open_in_the_cache_and_state_line_extension_draws_only_real_counts(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-05'));
        $html = $this->as('owner')->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('overdue', $html);
        $this->assertStringContainsString('Tax coming up', $html);
    }
}
