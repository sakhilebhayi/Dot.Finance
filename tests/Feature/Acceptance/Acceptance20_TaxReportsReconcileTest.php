<?php

namespace Tests\Feature\Acceptance;

use App\Models\Tax\TaxReturn;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\PostingLine;
use App\Services\Accounting\PostingRequest;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Owner criterion 20: tax reports reconcile with the financial records. The VAT201 working paper for the month, prepared through the
 * tax routes, equals the hand-worked figures; its output and input agree with the VAT accounts and with the VAT report; each field
 * lists the journal lines behind it; and a posting that bypasses the tax codes shows up as a difference instead of being absorbed.
 * "Prepared" is the furthest the software goes: it never files with SARS.
 *
 * By hand (see the Integration tests): output 4,500.00 + 60.00 = 4,560.00; input 1,050.00 + 300.00 = 1,350.00; payable 3,210.00.
 */
class Acceptance20_TaxReportsReconcileTest extends AcceptanceCase
{
    private TaxReturn $return;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMonth(false);
        Carbon::setTestNow('2026-12-01 09:00:00');      // the period has ended: a final return can be prepared
    }

    private function prepareReturn(): TaxReturn
    {
        $accountant = $this->memberAs('accountant');
        $this->asMember($accountant)->post(route('tax.vat.start'), ['period_start' => '2026-11-01'])->assertSessionHasNoErrors();
        $return = TaxReturn::query()->forContext($this->ledger)->firstOrFail();
        $this->asMember($accountant)->post(route('tax.returns.prepare', $return))->assertSessionHasNoErrors();

        return $return->fresh();
    }

    /** The working paper CSV as field => amount string. @return array<string, string> */
    private function boxes(TaxReturn $return): array
    {
        $out = [];
        foreach ($this->csvRows($this->asOwner()->get(route('tax.returns.csv', $return))->assertOk()->getContent()) as $row) {
            if (count($row) >= 5 && preg_match('/^\d+A?$/', $row[0]) && in_array($row[3], ['input', 'calc'], true)) {
                $out[$row[0]] = $row[2];
            }
        }

        return $out;
    }

    public function test_the_prepared_vat201_equals_the_hand_worked_figures_and_is_marked_prepared_not_submitted(): void
    {
        $return = $this->prepareReturn();
        $this->assertSame('prepared', $return->status);

        $boxes = $this->boxes($return);
        $this->assertSame(['1' => '34500.00', '2' => '2000.00', '3' => '500.00', '4' => '4500.00', '12' => '60.00', '13' => '4560.00', '15' => '1050.00', '18' => '300.00', '19' => '1350.00', '20' => '3210.00'],
            array_intersect_key($boxes, array_flip(['1', '2', '3', '4', '12', '13', '15', '18', '19', '20'])));
        $csv = $this->asOwner()->get(route('tax.returns.csv', $return))->getContent();
        $this->assertStringContainsString('PREPARED, NOT SUBMITTED', $csv);
        $this->assertStringContainsString('Dot.Finance does not submit anything to SARS', $csv);
        $this->assertStringContainsString('"Rule versions used"', $csv);
        $page = $this->asOwner()->get(route('tax.returns.show', $return))->assertOk()->getContent();
        $this->assertStringNotContainsString('Submitted to SARS', $page);
        $this->assertStringContainsString('Prepared, not submitted', $page);
    }

    public function test_the_return_reconciles_to_the_vat_accounts_and_to_the_vat_report(): void
    {
        $return = $this->prepareReturn();
        $csv = $this->asOwner()->get(route('tax.returns.csv', $return))->getContent();
        $this->assertMatchesRegularExpression('/"Net VAT in the books",,3210\.00/', $csv);
        $this->assertMatchesRegularExpression('/Difference,,0\.00/', $csv);
        $this->assertMatchesRegularExpression('/Unexplained,,0\.00/', $csv);

        $this->assertSame(420_000, -$this->balance('vat_output'));
        $this->assertSame(99_000, $this->balance('vat_input'));
        $this->assertSame(321_000, -$this->balance('vat_output') - $this->balance('vat_input'), 'the control accounts net to the return\'s payable');

        $vat = $this->report('vat', ['from' => '2026-11-01', 'to' => '2026-11-30']);
        $this->assertNotEmpty($vat->checks);
        $this->assertReportChecksPass($vat);
        $summary = $this->report('tax-summary', ['from' => '2026-11-01', 'to' => '2026-11-30']);
        $this->assertReportChecksPass($summary);
        $this->asOwner()->get(route('reports.vat', ['from' => '2026-11-01', 'to' => '2026-11-30']))->assertOk()->assertSee('4,200.00')->assertSee('990.00');
    }

    public function test_every_field_lists_the_journal_lines_behind_it_and_they_add_up_to_the_field(): void
    {
        $return = $this->prepareReturn();
        $rows = $this->csvRows($this->asOwner()->get(route('tax.returns.csv', $return))->getContent());
        $drill = [];
        foreach ($rows as $row) {
            if (count($row) === 6 && $row[1] === 'line') {
                $drill[$row[0]] = ($drill[$row[0]] ?? 0) + (int) round(((float) $row[5]) * 100);
            }
        }
        $this->assertSame(450_000, $drill['4'], 'field 4 is the sum of its lines');
        $this->assertSame(105_000, $drill['15']);
        $this->assertSame(200_000, $drill['2']);
        foreach (['1', '2', '3', '4', '12', '15', '18'] as $field) {
            $this->assertArrayHasKey($field, $drill, "field {$field} shows its lines");
        }
    }

    public function test_a_posting_that_bypasses_the_tax_codes_is_a_difference_and_blocks_ready_instead_of_being_absorbed(): void
    {
        $this->postStray();
        $this->asOwner()->get(route('tax.vat.index'))->assertOk();
        $accountant = $this->memberAs('accountant');
        $this->asMember($accountant)->post(route('tax.vat.start'), ['period_start' => '2026-11-01'])->assertSessionHasNoErrors();
        $return = TaxReturn::query()->forContext($this->ledger)->firstOrFail();

        $computed = LedgerContext::run($this->ledger, fn () => app(\App\Services\Tax\TaxReturnService::class)->compute($this->ledger, $return));
        $this->assertGreaterThan(0, $computed->reconciliation['untagged_count'] ?? 0, 'the untagged VAT line is counted');
        $this->asMember($accountant)->post(route('tax.returns.prepare', $return))->assertSessionHasErrors();
        $this->assertSame('draft', $return->fresh()->status, 'the return is not prepared while the books and the codes disagree');
    }

    public function test_a_prepared_return_is_frozen_when_later_entries_arrive_and_a_correction_is_a_new_revision(): void
    {
        $return = $this->prepareReturn();
        $hash = $return->fresh()->figures_hash ?? null;
        $before = $this->boxes($return);

        // a late credit note dated inside the period is possible while the period is open; the prepared figures do not move
        $this->issueCreditNote($this->inv1, '2026-11-28', 100_000);
        $this->assertSame($before, $this->boxes($return->fresh()), 'prepared figures are frozen');
        if ($hash !== null) {
            $this->assertSame($hash, $return->fresh()->figures_hash);
        }

        // a person records that they filed it, with the SARS reference; only then is it "submitted", and a correction is a revision
        $accountant = $this->memberAs('accountant');
        $this->asMember($accountant)->post(route('tax.returns.submission', $return), ['sars_reference' => 'SARS-REF-1', 'submitted_on' => '2026-12-01'])->assertSessionHasNoErrors();
        $this->assertSame('submitted', $return->fresh()->status);
        $this->asMember($accountant)->post(route('tax.returns.revision', $return))->assertSessionHasNoErrors();
        $revision = TaxReturn::query()->forContext($this->ledger)->where('revision', 2)->firstOrFail();
        $this->assertSame('draft', $revision->status);
        $this->assertSame($before, $this->boxes($return->fresh()), 'the submitted return is exactly as it was');
        $recorded = $this->auditRows('submission_recorded', 'tax_return')->first();
        $this->assertNotNull($recorded, 'the recording of the submission is audited');
        $this->assertSame($accountant->id, (int) $recorded->actor_user_id);
        $this->assertSame(['prepared', 'submission_recorded', 'revision_started'], $this->auditRows(null, 'tax_return')->pluck('action')->reject(fn ($a) => $a === 'created')->values()->all());
    }

    public function test_another_ledgers_return_cannot_be_opened_or_exported(): void
    {
        $return = $this->prepareReturn();
        $other = $this->user();
        $company = $this->company($other, 'Elsewhere');
        foreach (['tax.returns.show', 'tax.returns.workpaper', 'tax.returns.csv'] as $name) {
            $this->inCompany($other, $company)->get(route($name, $return))->assertNotFound();
        }
    }

    private function postStray(): void
    {
        LedgerContext::run($this->ledger, fn () => app(JournalPoster::class)->post(new PostingRequest(CarbonImmutable::parse('2026-11-22'), 'manual', 'stray', 1, 'VAT booked by hand', [
            new PostingLine($this->gl('6190')->id, debitCents: 10_000), new PostingLine($this->gl('vat_input')->id, debitCents: 1_500), new PostingLine($this->gl('ap')->id, creditCents: 11_500),
        ])));
    }
}
