<?php

namespace Tests\Feature\Integration;

use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalLine;
use App\Models\Purchases\ExpenseClaimLine;
use App\Models\Tax\TaxReturn;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\PostingLine;
use App\Services\Accounting\PostingRequest;
use App\Services\Tax\TaxProfiles;
use App\Services\Tax\TaxReturnService;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RunsAMonth;
use Tests\TestCase;

/**
 * The tax tagging contract (D-805) across the three modules that meet in a VAT201: Sales issues the tax invoices and credit note, Purchases
 * enters the bills, the credit note and the claim, Tax prepares the return from the journal lines they posted. The October ledger of
 * RunsAMonth is worked out by hand in MonthInTheLifeTest; the boxes below are derived from it again here, box by box:
 *
 *   1   standard-rated supplies, VAT inclusive    11,500.00 (INV-1 consulting) + 23,000.00 (INV-2) = 34,500.00
 *   2   zero-rated                                 2,000.00        3   exempt     500.00
 *   4   output tax on field 1                      1,500.00 + 3,000.00 = 4,500.00
 *   12  credit notes received (Paper Co)             60.00         13  total output = 4,500.00 + 60.00 = 4,560.00
 *   15  input tax, other goods and services        600.00 (bill) + 300.00 (toner) + 150.00 (fuel claim) = 1,050.00;
 *                                                  the entertainment bill's 135.00 is blocked and never reaches a box
 *   18  credit notes issued (Beta)                 300.00          19  total input = 1,050.00 + 300.00 = 1,350.00
 *   20  payable                                    4,560.00 - 1,350.00 = 3,210.00
 */
class VatReturnIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use RunsAMonth;

    private function expectedBoxes(): array
    {
        return [
            '1' => 3_450_000, '1A' => 0, '2' => 200_000, '2A' => 0, '3' => 50_000, '4' => 450_000, '4A' => 0, '5' => 0, '6' => 0, '7' => 0, '8' => 0, '9' => 0,
            '10' => 0, '11' => 0, '12' => 6_000, '13' => 456_000, '14' => 0, '14A' => 0, '15' => 105_000, '15A' => 0, '16' => 0, '17' => 0, '18' => 30_000,
            '19' => 135_000, '20' => 321_000,
        ];
    }

    private function newReturn(): TaxReturn
    {
        return LedgerContext::run($this->ledger, fn () => app(TaxReturnService::class)->start(
            $this->ledger, 'vat201', CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'), 'November 2026', app(TaxProfiles::class)->forLedger($this->ledger), $this->owner,
        ));
    }

    public function test_both_sides_of_every_vat_posting_carry_the_same_tax_code(): void
    {
        $this->runMonth(bank: false);
        $ledger = $this->ledger;

        $entries = JournalEntry::query()->forContext($ledger)->whereIn('source_type', ['invoice', 'credit_note', 'bill'])->get();
        $this->assertGreaterThanOrEqual(7, $entries->count());

        $vatLines = 0;
        foreach ($entries as $entry) {
            $lines = JournalLine::query()->forContext($ledger)->where('journal_entry_id', $entry->id)->with('glAccount')->get();
            foreach ($lines->filter(fn ($l) => in_array($l->glAccount->system_key, ['vat_output', 'vat_input'], true)) as $vat) {
                $vatLines++;
                $this->assertNotNull($vat->tax_code_id, "entry {$entry->entry_number}: a VAT line without a tax code");
                $net = $lines->filter(fn ($l) => in_array($l->glAccount->class, ['income', 'expense'], true) && (int) $l->tax_code_id === (int) $vat->tax_code_id);
                $this->assertGreaterThan(0, $net->count(), "entry {$entry->entry_number}: no net line carries the VAT line's tax code");
            }
        }
        $this->assertSame(7, $vatLines, 'two invoices, the credit note, the bill, its credit note, the toner and the fuel claim; the entertainment bill has no VAT line');
    }

    public function test_the_boxes_the_drill_downs_and_the_control_account_agree_with_the_hand_computed_figures(): void
    {
        $this->runMonth(bank: false);

        $computed = LedgerContext::run($this->ledger, fn () => app(TaxReturnService::class)->compute($this->ledger, $this->newReturn()));

        foreach ($this->expectedBoxes() as $code => $cents) {
            $this->assertSame($cents, $computed->amount($code), "box {$code}");
        }
        $this->assertSame(456_000, $computed->outputTaxCents);
        $this->assertSame(135_000, $computed->inputTaxCents);
        $this->assertSame(321_000, $computed->payableCents);

        // the VAT control reconciles to the general ledger
        $rec = $computed->reconciliation;
        $this->assertTrue($rec['ties'] ?? false);
        $this->assertSame(420_000, $rec['gl_output_cents']);
        $this->assertSame(99_000, $rec['gl_input_cents']);
        $this->assertSame(321_000, $rec['gl_net_cents']);
        $this->assertSame(0, $rec['difference_cents']);
        $this->assertSame(0, $rec['untagged_count']);
        $this->assertSame(420_000, -$this->balance('vat_output'));
        $this->assertSame(99_000, $this->balance('vat_input'));

        // every box is the sum of the journal lines that make it up, and each of those lines is in this ledger and in the period
        foreach ($computed->boxes as $box) {
            if ($box['kind'] === 'calc' || $box['amount_cents'] === 0) {
                continue;
            }
            $this->assertNotEmpty($box['contributors'], "box {$box['code']} has nothing behind it");
            // fields 12 and 18 are credit notes: the box shows the VAT as a positive amount, the lines behind it show their journal sign
            $this->assertSame($box['amount_cents'], in_array($box['code'], ['12', '18'], true) ? -array_sum(array_column($box['contributors'], 'amount_cents')) : array_sum(array_column($box['contributors'], 'amount_cents')), "box {$box['code']}: its drill-down does not add up");
            foreach ($box['contributors'] as $c) {
                $entry = JournalEntry::query()->forContext($this->ledger)->find($c['entry_id']);
                $this->assertNotNull($entry, "box {$box['code']} points at an entry that is not in this ledger");
                $this->assertGreaterThanOrEqual('2026-11-01', $entry->entry_date->toDateString());
                $this->assertLessThanOrEqual('2026-11-30', $entry->entry_date->toDateString());
            }
        }

        // the entertainment bill: 135.00 of VAT that may not be claimed is cost, so no VAT line, and no box
        $entry = JournalEntry::query()->forContext($this->ledger)->findOrFail($this->bill2->posted_entry_id);
        $keys = JournalLine::query()->forContext($this->ledger)->where('journal_entry_id', $entry->id)->with('glAccount')->get()->map(fn ($l) => $l->glAccount->system_key)->filter()->all();
        $this->assertNotContains('vat_input', $keys);
        $this->assertSame(103_500, $this->bill2->fresh()->total_cents);
        $this->assertSame(0, $this->bill2->fresh()->claimable_tax_cents);
    }

    public function test_a_return_is_prepared_only_with_its_evidence_and_the_figures_freeze(): void
    {
        $this->runMonth(bank: false);
        $service = app(TaxReturnService::class);
        $profile = app(TaxProfiles::class)->forLedger($this->ledger);
        $return = $this->newReturn();
        $today = CarbonImmutable::parse('2026-12-02');

        // before: the review names exactly the three records whose tax invoice is missing, and the claim is judged by its receipts
        $computed = LedgerContext::run($this->ledger, fn () => $service->compute($this->ledger, $return));
        $noFile = collect($computed->reconciliation['review'])->where('code', 'input_vat_no_document')->pluck('entry_id')->sort()->values()->all();
        $this->assertSame(collect([$this->bill1, $this->bill3])->map(fn ($b) => $b->fresh()->posted_entry_id)->push(
            JournalEntry::query()->forContext($this->ledger)->where('source_type', 'bill')->where('source_id', 'claim-'.$this->claim->id)->value('id')
        )->sort()->values()->all(), $noFile);
        $this->assertSame([], collect($computed->reconciliation['review'])->where('code', 'input_vat_no_supplier_vat_number')->all(), 'the claim records its merchant\'s VAT number on the receipt line, which is what counts');

        // the evidence: the supplier's tax invoices on the bills, the merchant's receipt on the claim line
        $this->attachFile($this->bill1);
        $this->attachFile($this->bill3);
        $this->attachFile(LedgerContext::run($this->ledger, fn () => ExpenseClaimLine::query()->where('expense_claim_id', $this->claim->id)->firstOrFail()));

        $computed = LedgerContext::run($this->ledger, fn () => $service->compute($this->ledger, $return));
        $this->assertSame([], collect($computed->reconciliation['review'])->reject(fn ($i) => $i['resolved'])->all(), 'nothing left to review');

        $prepared = LedgerContext::run($this->ledger, fn () => $service->prepare($this->ledger, $return, $profile, $this->owner, false, $today));
        $this->assertSame('prepared', $prepared->status);
        $this->assertSame(321_000, $prepared->payable_cents);
        $this->assertSame(456_000, $prepared->output_tax_cents);
        $this->assertSame(135_000, $prepared->input_tax_cents);
        $this->assertTrue($service->figuresIntact($prepared));
        foreach ($this->expectedBoxes() as $code => $cents) {
            $box = collect($prepared->figures['boxes'])->firstWhere('code', $code);
            $this->assertSame($cents, (int) $box['amount_cents'], "frozen box {$code}");
        }

        // the person files by hand and records it; the VAT control account is then settled from the books
        $submitted = LedgerContext::run($this->ledger, fn () => $service->recordSubmission($this->ledger, $prepared, 'SARS-REF-1', CarbonImmutable::parse('2026-12-20'), $this->owner, null, null, $today->addDays(18)));
        $this->assertSame('submitted', $submitted->status);

        // the page and the working paper show the same figures
        $this->get(route('tax.returns.show', $submitted))->assertOk()->assertSee('3,210.00');
        $this->get(route('tax.returns.workpaper', $submitted))->assertOk()->assertSee('3,210.00')->assertSee('Output VAT: Tax invoice INV-00001', false);
    }

    public function test_a_vat_posting_with_no_tax_code_is_shown_and_blocks_the_return_until_it_is_dealt_with(): void
    {
        $this->runMonth(bank: false);

        // a manual journal straight onto the VAT output account, with no tax code: the books now carry 50.00 that no box knows about
        LedgerContext::run($this->ledger, fn () => app(JournalPoster::class)->post(new PostingRequest(
            CarbonImmutable::parse('2026-11-25'), 'manual', 'tamper-1', 1, 'Untagged VAT adjustment',
            [new PostingLine('vat_output', creditCents: 5_000), new PostingLine('suspense', debitCents: 5_000)],
        )));

        $return = $this->newReturn();
        $computed = LedgerContext::run($this->ledger, fn () => app(TaxReturnService::class)->compute($this->ledger, $return));

        $this->assertSame(321_000, $computed->amount('20'), 'the return still says what the tagged postings say');
        $this->assertSame(326_000, $computed->reconciliation['gl_net_cents'], 'the control account now says 50.00 more');
        $this->assertSame(5_000, $computed->reconciliation['difference_cents']);
        $this->assertSame(1, $computed->reconciliation['untagged_count'], 'and names the line that explains it');

        $checks = LedgerContext::run($this->ledger, fn () => app(TaxReturnService::class)->checks($this->ledger, $return, $computed, app(TaxProfiles::class)->forLedger($this->ledger), false, CarbonImmutable::parse('2026-12-02')));
        $this->assertFalse($checks['ready']);
        $this->assertSame(['Unresolved: A line on the VAT output account has no tax code, so it is in no box.'], $checks['blockers']);
    }
}
