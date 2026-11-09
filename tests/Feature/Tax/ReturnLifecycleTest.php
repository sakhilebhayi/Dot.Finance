<?php

namespace Tests\Feature\Tax;

use App\Models\Accounting\JournalLine;
use App\Models\FinanceAuditLog;
use App\Models\Shared\TaxRate;
use App\Models\Tax\TaxDeadline;
use App\Models\Tax\TaxProfile;
use App\Models\Tax\TaxReturn;
use App\Models\Tax\TaxReturnAdjustment;
use App\Services\Accounting\Chart;
use App\Services\Accounting\GlQuery;
use App\Services\Accounting\PostingLine;
use App\Services\Tax\DeadlineGenerator;
use App\Services\Tax\Exceptions\NotReady;
use App\Services\Tax\Exceptions\TaxException;
use App\Services\Tax\TaxReturnService;
use App\Services\Tax\Vat201Builder;
use App\Services\Tax\VatReviewQueue;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTaxFixtures;
use Tests\TestCase;

/** Preparing, freezing, recording a person's submission and payment, revisions, and the guarantees that make a submitted return immutable. */
class ReturnLifecycleTest extends TestCase
{
    use BuildsTaxFixtures;
    use RefreshDatabase;

    private TaxProfile $profile;

    private TaxReturnService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->taxLedger();
        $this->travelTo(CarbonImmutable::parse('2026-11-09 10:00'));
        $this->profile = $this->vatProfile();
        $this->svc = app(TaxReturnService::class);
        $this->sale('2026-09-10', 100_000);   // output 15,000
        $this->purchase('2026-09-15', 40_000); // input 6,000
        LedgerContext::run($this->tax, fn () => app(DeadlineGenerator::class)->generate($this->tax, $this->profile, CarbonImmutable::today()));
    }

    private function start(string $from = '2026-09-01', string $to = '2026-10-31'): TaxReturn
    {
        return LedgerContext::run($this->tax, fn () => $this->svc->start($this->tax, 'vat201', CarbonImmutable::parse($from), CarbonImmutable::parse($to), 'Sep to Oct 2026', $this->profile, $this->people['owner']));
    }

    private function prepare(TaxReturn $r): TaxReturn
    {
        return LedgerContext::run($this->tax, fn () => $this->svc->prepare($this->tax, $r, $this->profile, $this->people['owner']));
    }

    private function submit(TaxReturn $r, string $ref = 'VAT-REF-123', string $on = '2026-11-05'): TaxReturn
    {
        return LedgerContext::run($this->tax, fn () => $this->svc->recordSubmission($this->tax, $r, $ref, CarbonImmutable::parse($on), $this->people['owner']));
    }

    public function test_a_clean_period_is_prepared_with_frozen_figures_a_hash_and_the_rule_versions(): void
    {
        $r = $this->prepare($this->start());

        $this->assertSame('prepared', $r->status);
        $this->assertSame(15_000, $r->output_tax_cents);
        $this->assertSame(6_000, $r->input_tax_cents);
        $this->assertSame(9_000, $r->payable_cents);
        $this->assertSame(64, strlen($r->figures_hash));
        $this->assertTrue($this->svc->figuresIntact($r));
        $this->assertNotEmpty($r->rule_versions);
        $this->assertContains('tax_rates#'.TaxRate::query()->where('rate_key', 'ZA_VAT_STD')->value('id'), array_column($r->rule_versions, 'version'));
        $this->assertSame('prepared', TaxDeadline::query()->forContext($this->tax)->where('obligation', 'vat201')->where('period_end', '2026-10-31')->first()->status);
        $this->assertTrue($r->reconciliation['ties']);
    }

    public function test_a_period_that_has_not_ended_cannot_be_prepared(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00'));
        try {
            $this->prepare($this->start());
            $this->fail('should be blocked');
        } catch (NotReady $e) {
            $this->assertStringContainsString('not ended', implode(' ', $e->reasons()));
        }
    }

    public function test_an_unresolved_error_blocks_and_accepting_it_with_a_note_unblocks(): void
    {
        $e = $this->sale('2026-09-20', 50_000, 'ZA_VAT_STD', 0); // a standard sale with no VAT line
        $r = $this->start();
        try {
            $this->prepare($r);
            $this->fail('should be blocked');
        } catch (NotReady $ex) {
            $this->assertStringContainsString('no VAT line', implode(' ', $ex->reasons()));
        }

        $lineId = JournalLine::withoutGlobalScopes()->where('journal_entry_id', $e->id)->where('gl_account_id', $this->glByName('Sales', $this->tax))->value('id');
        LedgerContext::run($this->tax, fn () => app(VatReviewQueue::class)->resolve($this->tax, $lineId, 'standard_without_vat', 'accepted', 'Customer is a diplomat: no VAT due, code to be corrected', $this->people['owner']));
        $this->assertSame('prepared', $this->prepare($r)->status);
    }

    public function test_untagged_vat_postings_are_explained_in_the_reconciliation_and_block_until_looked_at(): void
    {
        $this->postEntry($this->tax, '2026-09-22', 'manual', 'Manual VAT with no code', [
            new PostingLine('vat_output', creditCents: 7_000),
            new PostingLine('suspense', debitCents: 7_000),
        ]);
        $r = $this->start();
        $computed = LedgerContext::run($this->tax, fn () => $this->svc->compute($this->tax, $r));
        $rec = $computed->reconciliation;

        $this->assertSame(16_000, $rec['gl_net_cents'], 'books: 15,000 + 7,000 output less 6,000 input');
        $this->assertSame(9_000, $rec['return_from_books_cents']);
        $this->assertSame(7_000, $rec['difference_cents']);
        $this->assertSame(7_000, $rec['explained_cents'], 'the untagged line explains it exactly');
        $this->assertSame(0, $rec['unexplained_cents']);
        $this->assertSame(1, $rec['untagged_count']);

        $this->expectException(NotReady::class);
        $this->prepare($r);
    }

    public function test_a_preparation_can_be_withdrawn_with_a_reason_and_prepared_again(): void
    {
        $r = $this->prepare($this->start());
        $w = LedgerContext::run($this->tax, fn () => $this->svc->withdraw($this->tax, $r, 'Found a missing invoice', $this->people['owner']));
        $this->assertSame('draft', $w->status);
        $this->assertNull($w->figures_hash);
        $this->assertSame('open', TaxDeadline::query()->forContext($this->tax)->where('obligation', 'vat201')->where('period_end', '2026-10-31')->first()->status);

        $this->expectException(TaxException::class);
        LedgerContext::run($this->tax, fn () => $this->svc->withdraw($this->tax, $w, 'again', $this->people['owner']));
    }

    public function test_adjustments_need_a_reason_a_real_box_and_a_draft_return(): void
    {
        $r = $this->start();
        $add = fn (string $box, int $cents, string $reason = 'Bad debt written off after liquidation', ?TaxReturn $on = null) => LedgerContext::run($this->tax, fn () => $this->svc->addAdjustment($this->tax, $on ?? $r, $box, 'bad_debt', $cents, $reason, $this->people['owner']));

        $adj = $add('17', 1_200);
        $this->assertSame(1_200, $adj->amount_cents);
        $computed = LedgerContext::run($this->tax, fn () => $this->svc->compute($this->tax, $r));
        $this->assertSame(7_200, $computed->amount('19'));
        $this->assertSame(7_800, $computed->payableCents);

        foreach ([['13', 100, 'a calculated field'], ['17', 0, 'zero'], ['17', 500, 'no']] as [$box, $cents, $why]) {
            try {
                $add($box, $cents, $why === 'no' ? 'x' : 'A proper reason here');
                $this->fail("{$why} should be refused");
            } catch (TaxException) {
                $this->assertTrue(true);
            }
        }

        LedgerContext::run($this->tax, fn () => $this->svc->voidAdjustment($this->tax, $r, $adj, 'Entered twice', $this->people['owner']));
        $this->assertSame(6_000, LedgerContext::run($this->tax, fn () => $this->svc->compute($this->tax, $r))->amount('19'));
        $this->assertNotNull($adj->fresh()->voided_at);
        $this->assertSame(1, TaxReturnAdjustment::query()->forContext($this->tax)->count(), 'voided, never deleted');

        $prepared = $this->prepare($r);
        $this->expectException(TaxException::class);
        $add('17', 100, 'After it was prepared', $prepared);
    }

    public function test_submission_is_recorded_by_a_person_with_a_reference_and_freezes_the_return(): void
    {
        $r = $this->prepare($this->start());

        foreach ([['', '2026-11-05'], ['REF', '2026-12-31'], ['REF', '2026-10-15']] as [$ref, $on]) {
            try {
                $this->submit($r, $ref, $on);
                $this->fail('refused');
            } catch (TaxException) {
                $this->assertTrue(true);
            }
        }

        $s = $this->submit($r);
        $this->assertSame('submitted', $s->status);
        $this->assertSame('VAT-REF-123', $s->sars_reference);
        $this->assertSame($this->people['owner']->id, $s->submitted_by_user_id);
        $this->assertSame('submitted', TaxDeadline::query()->forContext($this->tax)->where('obligation', 'vat201')->where('period_end', '2026-10-31')->first()->status);
        $this->assertStringContainsString('Recorded as submitted by a person', $s->statusLabel());

        try {
            $this->submit($s, 'ANOTHER');
            $this->fail('a second submission');
        } catch (TaxException $e) {
            $this->assertStringContainsString('cannot change', $e->getMessage());
        }
    }

    public function test_a_draft_cannot_be_recorded_as_submitted(): void
    {
        $this->expectException(TaxException::class);
        $this->submit($this->start());
    }

    public function test_a_submitted_return_is_immutable_in_the_model_and_in_the_database(): void
    {
        $s = $this->submit($this->prepare($this->start()));

        // the model
        foreach (['payable_cents' => 1, 'sars_reference' => 'CHANGED', 'period_end' => '2026-12-31', 'figures_hash' => str_repeat('a', 64)] as $column => $value) {
            try {
                $fresh = TaxReturn::query()->forContext($this->tax)->find($s->id);
                LedgerContext::run($this->tax, fn () => $fresh->forceFill([$column => $value])->save());
                $this->fail("the model changed {$column}");
            } catch (\LogicException $e) {
                $this->assertStringContainsString('frozen', $e->getMessage());
            }
        }
        $fresh = TaxReturn::query()->forContext($this->tax)->find($s->id);
        try {
            $fresh->forceFill(['status' => 'draft'])->save();
            $this->fail('status went backwards');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }
        try {
            TaxReturn::query()->forContext($this->tax)->find($s->id)->delete();
            $this->fail('deleted');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }

        // the database, going around the model
        foreach (['payable_cents' => 1, 'sars_reference' => "'CHANGED'", 'submitted_on' => "'2026-01-01'", 'status' => "'draft'", 'figures_hash' => "'x'"] as $column => $value) {
            try {
                DB::transaction(fn () => DB::update("UPDATE tax_returns SET {$column} = {$value} WHERE id = ?", [$s->id]));
                $this->fail("the database let {$column} change");
            } catch (QueryException $e) {
                $this->assertStringContainsString('frozen', $e->getMessage());
            }
        }
        try {
            DB::transaction(fn () => DB::delete('DELETE FROM tax_returns WHERE id = ?', [$s->id]));
            $this->fail('the database deleted it');
        } catch (QueryException $e) {
            $this->assertStringContainsString('draft', $e->getMessage());
        }
        try {
            DB::transaction(fn () => DB::table('tax_return_adjustments')->insert($this->tax->ownerAttributes() + ['tax_return_id' => $s->id, 'box_code' => '17', 'kind' => 'other', 'amount_cents' => 5, 'reason' => 'late', 'created_at' => now(), 'updated_at' => now()]));
            $this->fail('an adjustment was added after submission');
        } catch (QueryException $e) {
            $this->assertStringContainsString('draft', $e->getMessage());
        }
        $this->assertSame(9_000, DB::table('tax_returns')->where('id', $s->id)->value('payable_cents'));
    }

    public function test_the_database_will_not_accept_a_submitted_return_without_its_reference(): void
    {
        $r = $this->prepare($this->start());
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::update("UPDATE tax_returns SET status = 'submitted' WHERE id = ?", [$r->id]));
    }

    public function test_payment_is_recorded_once_after_submission_and_a_refund_has_nothing_to_pay(): void
    {
        $s = $this->submit($this->prepare($this->start()));
        $paid = LedgerContext::run($this->tax, fn () => $this->svc->recordPayment($this->tax, $s, 9_000, CarbonImmutable::parse('2026-11-08'), 'PRN 4411', $this->people['owner']));
        $this->assertSame('paid', $paid->status);
        $this->assertSame(9_000, $paid->paid_cents);
        $this->assertSame('paid', TaxDeadline::query()->forContext($this->tax)->where('obligation', 'vat201')->where('period_end', '2026-10-31')->first()->status);
        $this->assertSame(9_000, $paid->payable_cents, 'recording a payment did not touch the frozen figures');

        $this->expectException(TaxException::class);
        LedgerContext::run($this->tax, fn () => $this->svc->recordPayment($this->tax, $paid, 9_000, CarbonImmutable::parse('2026-11-08'), null, $this->people['owner']));
    }

    public function test_payment_cannot_be_recorded_before_submission_or_for_a_refund(): void
    {
        $r = $this->prepare($this->start());
        try {
            LedgerContext::run($this->tax, fn () => $this->svc->recordPayment($this->tax, $r, 100, CarbonImmutable::parse('2026-11-08'), null, $this->people['owner']));
            $this->fail('before submission');
        } catch (TaxException) {
            $this->assertTrue(true);
        }

        $this->purchase('2026-07-10', 1_000_000); // input 150,000 in an earlier period => a refund there
        $refund = $this->start('2026-07-01', '2026-08-31');
        $refund = $this->prepare($refund);
        $this->assertLessThan(0, $refund->payable_cents);
        $refund = $this->submit($refund);
        $this->expectException(TaxException::class);
        LedgerContext::run($this->tax, fn () => $this->svc->recordPayment($this->tax, $refund, 100, CarbonImmutable::parse('2026-11-08'), null, $this->people['owner']));
    }

    public function test_a_correction_after_submission_is_a_revision_and_the_original_stays(): void
    {
        $s = $this->submit($this->prepare($this->start()));
        $rev = LedgerContext::run($this->tax, fn () => $this->svc->startRevision($this->tax, $s, $this->people['owner']));

        $this->assertSame(2, $rev->revision);
        $this->assertSame('draft', $rev->status);
        $this->assertSame($s->id, $rev->supersedes_return_id);
        $this->assertSame('submitted', $s->fresh()->status);
        $this->assertSame($rev->id, $this->svc->current($this->tax, 'vat201', CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-10-31'))->id);

        $this->expectException(TaxException::class);
        LedgerContext::run($this->tax, fn () => $this->svc->startRevision($this->tax, $s->fresh(), $this->people['owner']));
    }

    public function test_a_payments_basis_profile_blocks_until_acknowledged(): void
    {
        $profile = $this->vatProfile(['vat_basis' => 'payments']);
        $r = LedgerContext::run($this->tax, fn () => $this->svc->start($this->tax, 'vat201', CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-10-31'), 'Sep to Oct 2026', $profile));
        try {
            LedgerContext::run($this->tax, fn () => $this->svc->prepare($this->tax, $r, $profile, $this->people['owner']));
            $this->fail('blocked');
        } catch (NotReady $e) {
            $this->assertStringContainsString('payments', implode(' ', $e->reasons()));
        }
        $done = LedgerContext::run($this->tax, fn () => $this->svc->prepare($this->tax, $r, $profile, $this->people['owner'], true));
        $this->assertSame('prepared', $done->status);
        $this->assertStringContainsString('acknowledged', implode(' ', $done->checks['warnings']));
    }

    public function test_a_business_that_is_not_registered_cannot_start_a_vat201(): void
    {
        $profile = $this->vatProfile(['vat_registered' => false]);
        $this->expectException(TaxException::class);
        LedgerContext::run($this->tax, fn () => $this->svc->start($this->tax, 'vat201', CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-10-31'), 'x', $profile));
    }

    public function test_the_settlement_journal_balances_and_clears_the_vat_accounts(): void
    {
        $r = $this->prepare($this->start());
        $lines = $this->svc->suggestSettlement($this->tax, $r);
        $this->assertSame(array_sum(array_column($lines, 'debit_cents')), array_sum(array_column($lines, 'credit_cents')));

        $entry = LedgerContext::run($this->tax, fn () => $this->svc->postSettlement($this->tax, $r, $this->people['owner']));
        $net = fn (string $key) => LedgerContext::run($this->tax, fn () => app(GlQuery::class)->netThrough($this->tax, app(Chart::class)->system($key)));
        $this->assertSame(0, $net('vat_output'));
        $this->assertSame(0, $net('vat_input'));
        $this->assertSame(-9_000, $net('vat_control'), 'owed to SARS: 15,000 output less 6,000 input');
        $this->assertSame($entry->id, $r->fresh()->settlement_entry_id);

        // the settlement does not distort the return it came from
        $again = LedgerContext::run($this->tax, fn () => app(Vat201Builder::class)->build($this->tax, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-10-31')));
        $this->assertSame(9_000, $again->payableCents);
        $rec = LedgerContext::run($this->tax, fn () => $this->svc->compute($this->tax, $r->fresh()))->reconciliation;
        $this->assertTrue($rec['ties']);
    }

    public function test_every_change_is_in_the_audit_log_with_who_did_it(): void
    {
        $s = $this->submit($this->prepare($this->start()));
        $actions = FinanceAuditLog::query()->forContext($this->tax)->where('subject_type', 'tax_return')->orderBy('id')->pluck('action')->all();
        $this->assertSame(['created', 'prepared', 'submission_recorded'], $actions);
        $row = FinanceAuditLog::query()->forContext($this->tax)->where('action', 'submission_recorded')->first();
        $this->assertSame($this->people['owner']->id, $row->actor_user_id);
        $this->assertStringContainsString('not submitted by Dot.Finance', $row->context);
    }
}
