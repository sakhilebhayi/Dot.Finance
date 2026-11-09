<?php

namespace Tests\Feature\Tax\Qa;

use App\Models\Tax\TaxProfile;
use App\Models\Tax\TaxReturn;
use App\Services\Accounting\Exceptions\PeriodClosed;
use App\Services\Accounting\Periods;
use App\Services\Accounting\PostingLine;
use App\Services\Tax\DeadlineGenerator;
use App\Services\Tax\Dto\ComputedReturn;
use App\Services\Tax\Exceptions\NotReady;
use App\Services\Tax\Exceptions\TaxException;
use App\Services\Tax\PayrollJournal;
use App\Services\Tax\PayrollRuns;
use App\Services\Tax\TaxReturnService;
use App\Services\Tax\Workpaper;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\BuildsTaxFixtures;
use Tests\TestCase;

/**
 * QA probes of tax integrity: can a prepared or submitted return be altered by any route (HTTP, model, raw SQL)? can a journal
 * line be re-tagged after a return used it? can a second settlement double the VAT control? do the exports always carry the
 * banner, period, basis and rule versions? does anything claim "submitted" without a recorded person and reference?
 */
class QaTaxIntegrityTest extends TestCase
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
        $this->sale('2026-09-10', 100_000);    // output 15,000
        $this->purchase('2026-09-15', 40_000);  // input 6,000
        LedgerContext::run($this->tax, fn () => app(DeadlineGenerator::class)->generate($this->tax, $this->profile, CarbonImmutable::today()));
    }

    private function start(): TaxReturn
    {
        return LedgerContext::run($this->tax, fn () => $this->svc->start($this->tax, 'vat201', CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-10-31'), 'Sep to Oct 2026', $this->profile, $this->people['owner']));
    }

    private function prepared(): TaxReturn
    {
        return LedgerContext::run($this->tax, fn () => $this->svc->prepare($this->tax, $this->start(), $this->profile, $this->people['owner']));
    }

    private function submitted(?TaxReturn $r = null): TaxReturn
    {
        return LedgerContext::run($this->tax, fn () => $this->svc->recordSubmission($this->tax, $r ?? $this->prepared(), 'QA-REF-1', CarbonImmutable::parse('2026-11-05'), $this->people['owner']));
    }

    private function as(string $role): static
    {
        return $this->inCompany($this->people[$role], $this->acme);
    }

    /** Raw SQL, no model: whatever a stray script or a careless migration could do. */
    private function raw(int $id, array $set): void
    {
        // inside a savepoint: on PostgreSQL a rejected statement would otherwise poison the surrounding test transaction
        DB::transaction(fn () => DB::table('tax_returns')->where('id', $id)->update($set));
    }

    // ── a prepared or submitted return cannot be altered by any route ──────────

    public function test_raw_sql_cannot_change_the_figures_or_rule_versions_of_a_prepared_return(): void
    {
        $r = $this->prepared();
        $figures = $r->figures;
        $figures['boxes'][0]['amount_cents'] += 1;

        foreach ([
            'figures' => json_encode($figures),
            'rule_versions' => json_encode([]),
            'reconciliation' => json_encode(['ties' => true]),
            'figures_hash' => str_repeat('a', 64),
            'payable_cents' => 1,
        ] as $column => $value) {
            try {
                $this->raw($r->id, [$column => $value]);
                $this->fail("raw SQL changed {$column} on a prepared return");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame($r->figures_hash, DB::table('tax_returns')->where('id', $r->id)->value('figures_hash'));
        $this->assertTrue($this->svc->figuresIntact(LedgerContext::run($this->tax, fn () => TaxReturn::query()->findOrFail($r->id))));
    }

    public function test_raw_sql_cannot_change_a_submitted_returns_figures_rules_channel_label_or_preparer(): void
    {
        $r = $this->submitted();
        $figures = $r->figures;
        $figures['boxes'][0]['amount_cents'] += 100;

        foreach ([
            'figures' => json_encode($figures),
            'rule_versions' => json_encode([]),
            'reconciliation' => json_encode([]),
            'checks' => json_encode([]),
            'channel' => 'manual',
            'period_label' => 'Something else',
            'prepared_by_user_id' => $this->people['viewer']->id,
            'sars_reference' => 'OTHER',
            'submitted_on' => '2026-11-06',
            'status' => 'prepared',
            'payable_cents' => 0,
        ] as $column => $value) {
            try {
                $this->raw($r->id, [$column => $value]);
                $this->fail("raw SQL changed {$column} on a submitted return");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_recorded_payment_cannot_be_rewritten_after_the_fact(): void
    {
        $r = $this->submitted();
        $paid = LedgerContext::run($this->tax, fn () => $this->svc->recordPayment($this->tax, $r, 900_000, CarbonImmutable::parse('2026-11-07'), 'PAY-1', $this->people['owner']));
        $this->assertSame('paid', $paid->status);

        foreach (['paid_cents' => 1, 'paid_on' => '2026-11-08', 'payment_reference' => 'X', 'paid_by_user_id' => $this->people['viewer']->id] as $column => $value) {
            try {
                $this->raw($r->id, [$column => $value]);
                $this->fail("raw SQL rewrote {$column} of a recorded payment");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(LogicException::class);
        LedgerContext::run($this->tax, function () use ($r) {
            $fresh = TaxReturn::query()->findOrFail($r->id);
            $fresh->paid_cents = 5;
            $fresh->save();
        });
    }

    public function test_the_model_refuses_to_change_frozen_json_columns_too(): void
    {
        $r = $this->submitted();
        $this->expectException(LogicException::class);
        LedgerContext::run($this->tax, function () use ($r) {
            $fresh = TaxReturn::query()->findOrFail($r->id);
            $figures = $fresh->figures;
            $figures['boxes'][0]['amount_cents'] = 999;
            $fresh->figures = $figures;
            $fresh->save();
        });
    }

    public function test_exports_and_views_may_still_stamp_the_export_counter_on_a_frozen_return(): void
    {
        $r = $this->submitted();
        $this->raw($r->id, ['export_count' => 3, 'last_exported_at' => now()]);
        $this->assertSame(3, (int) DB::table('tax_returns')->where('id', $r->id)->value('export_count'));
    }

    public function test_an_adjustment_cannot_reach_a_prepared_or_submitted_return_by_any_route(): void
    {
        $r = $this->prepared();

        // service
        try {
            LedgerContext::run($this->tax, fn () => $this->svc->addAdjustment($this->tax, $r, '17', 'bad_debt', 1_000, 'Late bad debt', $this->people['owner']));
            $this->fail('service accepted an adjustment on a prepared return');
        } catch (TaxException) {
            $this->addToAssertionCount(1);
        }

        // HTTP
        $this->as('accountant')->post(route('tax.returns.adjust', $r), ['box_code' => '17', 'kind' => 'bad_debt', 'direction' => 'increase', 'amount' => '10.00', 'reason' => 'Late bad debt'])->assertSessionHasErrors('adjustment');

        // raw SQL
        try {
            DB::transaction(fn () => DB::table('tax_return_adjustments')->insert(['tax_return_id' => $r->id, 'user_id' => $r->user_id, 'team_id' => $r->team_id, 'box_code' => '17', 'kind' => 'bad_debt', 'amount_cents' => 1_000, 'reason' => 'raw', 'created_at' => now(), 'updated_at' => now()]));
            $this->fail('raw insert added an adjustment to a prepared return');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, DB::table('tax_return_adjustments')->where('tax_return_id', $r->id)->count());
    }

    public function test_submission_payment_and_preparation_cannot_be_repeated_or_skipped_over_http(): void
    {
        $r = $this->start();

        // recording a submission before preparing is refused
        $this->as('owner')->post(route('tax.returns.submission', $r), ['sars_reference' => 'EARLY', 'submitted_on' => '2026-11-05'])->assertSessionHasErrors('submission');
        $this->assertSame('draft', $r->fresh()->status);

        $this->as('owner')->post(route('tax.returns.prepare', $r))->assertSessionHasNoErrors();
        $this->as('owner')->post(route('tax.returns.submission', $r), ['sars_reference' => 'FIRST', 'submitted_on' => '2026-11-05'])->assertSessionHasNoErrors();
        $this->as('owner')->post(route('tax.returns.submission', $r), ['sars_reference' => 'SECOND', 'submitted_on' => '2026-11-06'])->assertSessionHasErrors('submission');
        $this->as('owner')->post(route('tax.returns.withdraw', $r), ['reason' => 'Trying to take it back'])->assertSessionHasErrors('prepare');
        $this->as('owner')->post(route('tax.returns.prepare', $r))->assertSessionHasErrors('prepare');

        $fresh = $r->fresh();
        $this->assertSame(['submitted', 'FIRST'], [$fresh->status, $fresh->sars_reference]);

        // a payment cannot be recorded twice either
        $this->as('owner')->post(route('tax.returns.payment', $r), ['amount' => '90.00', 'paid_on' => '2026-11-07'])->assertSessionHasNoErrors();
        $this->as('owner')->post(route('tax.returns.payment', $r), ['amount' => '1.00', 'paid_on' => '2026-11-08'])->assertSessionHasErrors('payment');
        $this->assertSame(9_000, (int) $r->fresh()->paid_cents);
    }

    public function test_a_submission_needs_a_reference_a_date_after_the_period_and_a_person_in_the_database_too(): void
    {
        $r = $this->prepared();

        // the database refuses a "submitted" status without reference, date and person, whatever the code does
        foreach ([
            ['sars_reference' => null, 'submitted_on' => '2026-11-05', 'submitted_by_user_id' => $this->people['owner']->id],
            ['sars_reference' => '   ', 'submitted_on' => '2026-11-05', 'submitted_by_user_id' => $this->people['owner']->id],
            ['sars_reference' => 'REF', 'submitted_on' => null, 'submitted_by_user_id' => $this->people['owner']->id],
            ['sars_reference' => 'REF', 'submitted_on' => '2026-11-05', 'submitted_by_user_id' => null],
        ] as $partial) {
            try {
                $this->raw($r->id, ['status' => 'submitted'] + $partial);
                $this->fail('a submitted status was stored without a complete record');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        // a date inside the period or in the future is refused by the service
        foreach (['2026-10-31', '2026-11-10'] as $date) {
            try {
                LedgerContext::run($this->tax, fn () => $this->svc->recordSubmission($this->tax, $r, 'REF', CarbonImmutable::parse($date), $this->people['owner']));
                $this->fail("submission accepted with date {$date}");
            } catch (TaxException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ── journal lines cannot be re-tagged ──────────────────────────────────────

    public function test_a_journal_line_cannot_be_retagged_deleted_or_moved_after_a_return_used_it(): void
    {
        $this->submitted();
        $line = DB::table('journal_lines')->whereNotNull('tax_code_id')->first();
        $other = $this->taxCodeId('ZA_VAT_ZERO');

        foreach ([['tax_code_id' => $other], ['tax_code_id' => null], ['entry_date' => '2026-12-01'], ['debit_cents' => 1], ['gl_account_id' => 1]] as $set) {
            try {
                DB::transaction(fn () => DB::table('journal_lines')->where('id', $line->id)->update($set));
                $this->fail('journal line changed: '.json_encode($set));
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        try {
            DB::transaction(fn () => DB::table('journal_lines')->where('id', $line->id)->delete());
            $this->fail('journal line deleted');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    // ── "ready" is blocked when the VAT control does not reconcile ─────────────

    public function test_ready_is_false_when_the_return_does_not_tie_to_the_vat_accounts(): void
    {
        $r = $this->start();
        $computed = LedgerContext::run($this->tax, fn () => $this->svc->compute($this->tax, $r));
        $this->assertTrue($this->svc->checks($this->tax, $r, $computed, $this->profile)['ready']);

        $broken = new ComputedReturn($computed->kind, $computed->periodStart, $computed->periodEnd, $computed->boxes, $computed->outputTaxCents, $computed->inputTaxCents, $computed->payableCents, $computed->issues, $computed->ruleVersions, $computed->reconciliation, $computed->notes);
        $broken->reconciliation['ties'] = false;
        $broken->reconciliation['unexplained_cents'] = 1_234;

        $checks = $this->svc->checks($this->tax, $r, $broken, $this->profile);
        $this->assertFalse($checks['ready']);
        $this->assertStringContainsString('does not reconcile', implode(' ', $checks['blockers']));
        $this->assertStringContainsString('12.34', implode(' ', $checks['blockers']));

        // and a reconciliation that carries no verdict at all also blocks (missing is not "fine")
        $missing = new ComputedReturn($computed->kind, $computed->periodStart, $computed->periodEnd, $computed->boxes, $computed->outputTaxCents, $computed->inputTaxCents, $computed->payableCents, [], $computed->ruleVersions, [], []);
        $this->assertFalse($this->svc->checks($this->tax, $r, $missing, $this->profile)['ready']);
    }

    public function test_an_unresolved_error_in_the_books_blocks_preparing_and_an_open_period_does_too(): void
    {
        $this->sale('2026-09-20', 50_000, 'ZA_VAT_STD', 0);   // standard sale with no VAT line: an error-level review item
        $r = $this->start();
        try {
            LedgerContext::run($this->tax, fn () => $this->svc->prepare($this->tax, $r, $this->profile, $this->people['owner']));
            $this->fail('prepared with an unresolved error');
        } catch (NotReady $e) {
            $this->assertNotEmpty($e->reasons());
        }
        $this->assertSame('draft', $r->fresh()->status);

        // a period that has not ended cannot be prepared as final
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00'));
        try {
            LedgerContext::run($this->tax, fn () => $this->svc->prepare($this->tax, $r, $this->profile, $this->people['owner']));
            $this->fail('prepared before the period ended');
        } catch (NotReady $e) {
            $this->assertStringContainsString('has not ended', implode(' ', $e->reasons()));
        }
    }

    // ── a second settlement for the same period must not double the VAT control ─

    public function test_a_revision_cannot_post_a_second_settlement_for_the_same_period(): void
    {
        $first = $this->submitted();
        $this->grantPostJournal();
        $entry = LedgerContext::run($this->tax, fn () => $this->svc->postSettlement($this->tax, $first, $this->people['owner']));
        $this->assertNotNull($entry->id);

        $revision = LedgerContext::run($this->tax, fn () => $this->svc->startRevision($this->tax, $first, $this->people['owner']));
        $revision = LedgerContext::run($this->tax, fn () => $this->svc->prepare($this->tax, $revision, $this->profile, $this->people['owner']));

        try {
            LedgerContext::run($this->tax, fn () => $this->svc->postSettlement($this->tax, $revision, $this->people['owner']));
            $this->fail('a second settlement was posted for the same period');
        } catch (TaxException $e) {
            $this->assertStringContainsString('already', $e->getMessage());
        }

        $control = DB::table('journal_lines as l')->join('gl_accounts as g', 'g.id', '=', 'l.gl_account_id')->where('g.system_key', 'vat_control')->selectRaw('COALESCE(SUM(l.credit_cents),0) - COALESCE(SUM(l.debit_cents),0) AS net')->value('net');
        $this->assertSame(9_000, (int) $control, 'VAT control holds the period VAT once: output 15,000 less input 6,000');
        $this->assertSame(1, DB::table('journal_entries')->where('source_id', 'like', 'vat_settlement:%')->count());
    }

    private function grantPostJournal(): void
    {
        // the service itself is capability-free; the route asks for PostJournal. Nothing to grant here, kept for readability.
    }

    // ── exports: banner, period, basis, rule versions, and no false "submitted" ─

    public function test_every_export_in_every_status_carries_banner_period_basis_channel_and_rule_versions(): void
    {
        $r = $this->start();
        $responses = [];
        $snap = function (string $stage, TaxReturn $ret) use (&$responses): void {
            $responses[$stage] = [
                $this->as('owner')->get(route('tax.returns.workpaper', $ret))->assertOk()->getContent(),
                $this->as('owner')->get(route('tax.returns.csv', $ret))->assertOk()->getContent(),
            ];
        };
        $snap('draft', $r);
        $prepared = LedgerContext::run($this->tax, fn () => $this->svc->prepare($this->tax, $r, $this->profile, $this->people['owner']));
        $snap('prepared', $prepared);
        $submitted = LedgerContext::run($this->tax, fn () => $this->svc->recordSubmission($this->tax, $prepared, 'QA-REF-9', CarbonImmutable::parse('2026-11-05'), $this->people['owner']));
        $snap('submitted', $submitted);
        $paid = LedgerContext::run($this->tax, fn () => $this->svc->recordPayment($this->tax, $submitted, 900_000, CarbonImmutable::parse('2026-11-07'), 'PAY', $this->people['owner']));
        $snap('paid', $paid);

        foreach ($responses as $stage => [$html, $csv]) {
            foreach ([$html, $csv] as $kind => $body) {
                $label = "{$stage} ".($kind === 0 ? 'html' : 'csv');
                $this->assertStringContainsString('Sep to Oct 2026', $body, "{$label}: period label");
                $this->assertStringContainsString('2026-09-01', $body, "{$label}: period start");
                $this->assertStringContainsString('2026-10-31', $body, "{$label}: period end");
                $this->assertMatchesRegularExpression('/invoice/i', $body, "{$label}: basis");
                $this->assertMatchesRegularExpression('/efiling/i', $body, "{$label}: channel");
                $this->assertMatchesRegularExpression('/tax_rules#\d+|tax_rates#\d+/', $body, "{$label}: rule versions");
            }

            if (in_array($stage, ['draft', 'prepared'], true)) {
                $this->assertStringContainsString('NOT SUBMITTED', $html, "{$stage}: html banner");
                $this->assertStringContainsString('NOT SUBMITTED', $csv, "{$stage}: csv banner");
                $this->assertStringNotContainsString('RECORDED AS SUBMITTED', $html.$csv, "{$stage}: must not claim a submission");
            } else {
                // a submitted banner names the recorded reference and date, and says Dot.Finance did not submit it
                foreach ([$html, $csv] as $body) {
                    $this->assertStringContainsString('QA-REF-9', $body);
                    $this->assertStringContainsString('5 November 2026', $body);
                    $this->assertStringContainsString('Dot.Finance did not submit this to SARS', $body);
                    $this->assertStringContainsString($this->people['owner']->name, $body, 'the person who recorded it is named');
                }
            }
        }
    }

    public function test_the_submitted_banner_names_the_person_who_recorded_it(): void
    {
        $r = $this->submitted();
        $paper = LedgerContext::run($this->tax, fn () => app(Workpaper::class)->build($this->tax, $r));
        $this->assertStringContainsString($this->people['owner']->name, $paper['banner'], 'person + reference + date');
        $this->assertSame($this->people['owner']->name, $paper['submitted_by']);
    }

    public function test_no_tax_view_claims_a_submission_to_sars_by_the_software(): void
    {
        $dir = base_path('resources/views/tax');
        $hits = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                foreach (file($file->getPathname()) as $n => $line) {
                    $negated = preg_match('/\b(nothing|not|never|no)\b/i', $line) === 1;
                    if (! $negated && (preg_match('/(was|has been|is) (successfully )?(submitted|filed|sent) to SARS/i', $line) || preg_match('/submitted to SARS by Dot/i', $line))) {
                        $hits[] = $file->getPathname().':'.($n + 1);
                    }
                }
            }
        }
        $this->assertSame([], $hits);
        $this->assertSame('Recorded as submitted by a person', (new TaxReturn(['status' => 'submitted']))->statusLabel());
        $this->assertSame('Prepared, not submitted', (new TaxReturn(['status' => 'prepared']))->statusLabel());
    }

    public function test_every_working_paper_field_carries_one_of_the_four_basis_tags(): void
    {
        $r = $this->start();
        LedgerContext::run($this->tax, fn () => $this->svc->addAdjustment($this->tax, $r, '17', 'bad_debt', 7_500, 'Debtor liquidated in October', $this->people['owner']));
        LedgerContext::run($this->tax, fn () => $this->svc->addAdjustment($this->tax, $r, '4', 'correction', 100, 'Rounding on a manual invoice', $this->people['owner']));

        $paper = LedgerContext::run($this->tax, fn () => app(Workpaper::class)->build($this->tax, $r));
        $tags = [];
        foreach ($paper['boxes'] as $b) {
            $this->assertContains($b['basis'], ['Calculated', 'You entered', 'Calculated + You entered', 'Estimate', 'Suggestion'], "field {$b['code']}");
            $tags[$b['code']] = $b['basis'];
        }
        $this->assertSame('Calculated', $tags['1']);                      // lines from the books
        $this->assertSame('You entered', $tags['17']);                    // only the person's adjustment
        $this->assertSame('Calculated + You entered', $tags['4']);        // booked VAT plus a person's correction
        $this->assertSame('Calculated', $tags['20']);                     // arithmetic

        $html = $this->as('owner')->get(route('tax.returns.workpaper', $r))->assertOk()->getContent();
        $this->assertSame(count($paper['boxes']), preg_match_all('/<td>(Calculated|You entered|Calculated \+ You entered)<\/td>/', $html));
        $csv = $this->as('owner')->get(route('tax.returns.csv', $r))->assertOk()->getContent();
        $this->assertStringContainsString('Field,Description,Amount,Kind,Basis,Note', $csv);
    }

    // ── period locks ───────────────────────────────────────────────────────────

    public function test_every_journal_the_tax_module_posts_respects_the_period_lock(): void
    {
        $submitted = $this->submitted();
        LedgerContext::run($this->tax, fn () => app(Periods::class)->closeThrough($this->tax, CarbonImmutable::parse('2026-10-31'), $this->people['owner']));

        // a hand-posted VAT journal into the closed period is refused outright
        try {
            $this->postEntry($this->tax, '2026-10-31', 'manual', 'Late VAT journal', [
                new PostingLine('vat_output', creditCents: 100),
                new PostingLine('ar', debitCents: 100),
            ]);
            $this->fail('posted into a closed period');
        } catch (PeriodClosed) {
            $this->addToAssertionCount(1);
        }

        // the settlement does not bypass the lock: it goes on the first open day, with the reason in its memo
        $entry = LedgerContext::run($this->tax, fn () => $this->svc->postSettlement($this->tax, $submitted, $this->people['owner']));
        $this->assertSame('2026-11-01', $entry->entry_date->toDateString());
        $this->assertStringContainsString('the period itself is closed', $entry->memo);

        // a payroll summary for a closed month cannot be journalled either
        $run = LedgerContext::run($this->tax, fn () => app(PayrollRuns::class)->capture($this->tax, CarbonImmutable::parse('2026-09-01'), [
            'gross_pay_cents' => 10_000_000, 'paye_cents' => 1_000_000, 'uif_employee_cents' => 17_712, 'uif_employer_cents' => 17_712, 'sdl_cents' => 100_000, 'eti_claimed_cents' => 0, 'employee_count' => 3,
        ], $this->people['owner']));
        try {
            LedgerContext::run($this->tax, fn () => app(PayrollJournal::class)->post($this->tax, $run, $this->people['owner']));
            $this->fail('payroll journal posted into a closed period');
        } catch (PeriodClosed) {
            $this->addToAssertionCount(1);
        }
    }
}
