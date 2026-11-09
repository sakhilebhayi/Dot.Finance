<?php

namespace Tests\Feature\Intelligence;

use App\Models\FinanceAuditLog;
use App\Models\Intelligence\FinanceInsight;
use App\Models\RecurringRule;
use App\Models\Shared\Party;
use App\Models\Transaction;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\PostingLine;
use App\Services\Accounting\PostingRequest;
use App\Services\Intelligence\InsightEngine;
use App\Support\Ledger\LedgerContext;
use App\Support\Ui\StateLine;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Concerns\BuildsAccounting;
use Tests\Concerns\BuildsIntelFixtures;
use Tests\TestCase;

class InsightEngineTest extends TestCase
{
    use BuildsAccounting;
    use BuildsIntelFixtures;
    use RefreshDatabase;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixTime();
        $this->b = $this->business();
        // Plenty in the bank, so the runway rule stays quiet unless a test is about it.
        $this->b['bank']->forceFill(['opening_balance_cents' => 10000000])->save();
    }

    private function scan(?array $b = null): array
    {
        return app(InsightEngine::class)->scan(($b ?? $this->b)['context']);
    }

    /** @return Collection<int, FinanceInsight> */
    private function findings(?array $b = null, ?string $kind = null)
    {
        $b ??= $this->b;

        return LedgerContext::run($b['context'], fn () => FinanceInsight::query()->when($kind, fn ($q) => $q->where('kind', $kind))->get());
    }

    /** Six steady months of food spending (R900 to R1,100), so a median and a spread exist. */
    private function steadyFood(): void
    {
        foreach ([['2026-05-10', 90000], ['2026-06-10', 100000], ['2026-07-10', 110000], ['2026-08-10', 95000], ['2026-09-10', 105000], ['2026-10-10', 100000]] as [$d, $c]) {
            $this->spend($this->b['bank'], $this->b['food'], $c, $d, 'Groceries '.$d);
        }
    }

    public function test_a_spike_against_the_categorys_own_history_is_flagged_with_its_evidence(): void
    {
        $this->steadyFood();
        $this->spend($this->b['bank'], $this->b['food'], 350000, '2026-11-12', 'Catering for the launch');

        $result = $this->scan();

        $this->assertCount(1, $result['created']);
        $f = $this->findings(kind: 'expense_spike')->first();
        $this->assertSame('warning', $f->severity);          // 3.5x the median
        $this->assertSame('calculated', $f->basis);
        $this->assertSame('2026-11', $f->period_key);
        $this->assertStringContainsString('ZAR 3,500.00', $f->body);
        $this->assertStringContainsString('ZAR 1,000.00', $f->body);   // the median of the six months
        $labels = collect($f->evidence)->pluck('label')->implode('|');
        $this->assertStringContainsString('Catering for the launch', $labels);
        $this->assertStringContainsString('Typical month', $labels);
    }

    public function test_a_normal_month_a_small_rise_and_too_little_history_are_not_flagged(): void
    {
        $this->steadyFood();
        $this->spend($this->b['bank'], $this->b['food'], 118000, '2026-11-12', 'A bit more');   // within the usual spread
        $this->assertSame([], $this->scan()['created']);

        // A different category with only two months of history: no judgement.
        $this->spend($this->b['bank'], $this->b['fuel'], 10000, '2026-09-01');
        $this->spend($this->b['bank'], $this->b['fuel'], 10000, '2026-10-01');
        $this->spend($this->b['bank'], $this->b['fuel'], 900000, '2026-11-02');
        $this->assertSame([], $this->scan()['created']);
    }

    public function test_a_flat_history_still_needs_a_real_jump(): void
    {
        foreach (['2026-05-10', '2026-06-10', '2026-07-10', '2026-08-10', '2026-09-10', '2026-10-10'] as $d) {
            $this->spend($this->b['bank'], $this->b['food'], 100000, $d);
        }
        $this->spend($this->b['bank'], $this->b['food'], 105000, '2026-11-10');   // MAD is zero, but this is only +R50
        $this->assertSame([], $this->scan()['created']);

        $this->spend($this->b['bank'], $this->b['food'], 120000, '2026-11-11');   // now R2,250 against R1,000
        $this->assertCount(1, $this->scan()['created']);
    }

    public function test_possible_duplicates_are_found_but_recurring_pairs_and_different_amounts_are_not(): void
    {
        $this->spend($this->b['bank'], $this->b['food'], 45000, '2026-11-10', 'Checkers');
        $this->spend($this->b['bank'], $this->b['food'], 45000, '2026-11-11', 'checkers ');
        $this->spend($this->b['bank'], $this->b['food'], 45001, '2026-11-12', 'Checkers');            // one cent different
        $this->spend($this->b['bank'], $this->b['food'], 45000, '2026-10-01', 'Checkers');            // a month earlier

        $rule = RecurringRule::factory()->create(['team_id' => $this->b['team']->id, 'user_id' => null, 'account_id' => $this->b['bank']->id]);
        foreach (['2026-11-14', '2026-11-15'] as $d) {
            Transaction::factory()->create(['account_id' => $this->b['bank']->id, 'amount_cents' => 7000, 'description' => 'Daily subscription', 'date' => $d, 'recurring_rule_id' => $rule->id]);
        }

        $this->scan();
        $dups = $this->findings(kind: 'duplicate');

        $this->assertCount(1, $dups);
        $this->assertStringContainsString('heckers', $dups->first()->title);
        $this->assertCount(2, $dups->first()->evidence);
    }

    public function test_an_unusual_round_amount_is_flagged_only_where_the_category_is_not_round(): void
    {
        foreach ([12345, 23456, 34567, 45678, 56789, 6789] as $i => $c) {
            $this->spend($this->b['bank'], $this->b['fuel'], $c, '2026-10-0'.($i + 1));
        }
        $this->spend($this->b['bank'], $this->b['fuel'], 1000000, '2026-11-10', 'Fuel card top-up');

        // A category that is always round is not flagged.
        foreach ([500000, 1000000, 500000, 1500000, 500000, 1000000] as $i => $c) {
            $this->spend($this->b['bank'], $this->b['food'], $c, '2026-10-1'.$i);
        }
        $this->spend($this->b['bank'], $this->b['food'], 1000000, '2026-11-11', 'Round food');

        $this->scan();
        $round = $this->findings(kind: 'round_amount');

        $this->assertCount(1, $round);
        $this->assertStringContainsString('Fuel card top-up', $round->first()->title);
    }

    public function test_a_changed_bank_detail_is_critical_and_shows_no_values(): void
    {
        $party = Party::factory()->create(['team_id' => $this->b['team']->id, 'user_id' => null, 'name' => 'Pipes & Co', 'type' => 'supplier']);
        FinanceAuditLog::create($this->b['context']->ownerAttributes() + [
            'action' => 'updated', 'actor' => 'user', 'actor_user_id' => $this->b['owner']->id, 'subject_type' => 'party', 'subject_id' => $party->id,
            'subject_label' => 'Party Pipes & Co', 'changes' => ['bank_account' => ['changed' => true], 'name' => ['before' => 'a', 'after' => 'b']],
        ]);

        $this->scan();
        $f = $this->findings(kind: 'bank_details_changed')->first();

        $this->assertSame('critical', $f->severity);
        $this->assertStringContainsString('Pipes & Co', $f->title);
        $this->assertSame('bank_account', collect($f->evidence)->firstWhere('label', 'Fields changed')['value']);
        $this->assertStringNotContainsString('"before"', json_encode($f->evidence));
    }

    public function test_dormant_customers_and_revenue_concentration_read_receivables(): void
    {
        $this->enableAccounting($this->b['context']);
        $big = Party::factory()->create(['team_id' => $this->b['team']->id, 'user_id' => null, 'name' => 'Big Buyer', 'type' => 'customer']);
        $quiet = Party::factory()->create(['team_id' => $this->b['team']->id, 'user_id' => null, 'name' => 'Quiet Co', 'type' => 'customer']);
        $small = Party::factory()->create(['team_id' => $this->b['team']->id, 'user_id' => null, 'name' => 'Small Buyer', 'type' => 'customer']);

        LedgerContext::run($this->b['context'], function () use ($big, $quiet, $small) {
            $poster = app(JournalPoster::class);
            $n = 0;
            $sale = function (string $date, int $cents, Party $p) use ($poster, &$n) {
                $poster->post(new PostingRequest(CarbonImmutable::parse($date), 'manual', 'sale-'.(++$n), 1, 'Sale', [
                    new PostingLine('ar', debitCents: $cents, partyId: $p->id),
                    new PostingLine('sales', creditCents: $cents),
                ], $this->b['owner']->id));
            };
            foreach (['2026-04-02', '2026-06-02', '2026-08-02', '2026-10-02', '2026-11-02'] as $d) {
                $sale($d, 400000, $big);
            }
            foreach (['2025-12-01', '2026-01-05', '2026-02-05'] as $d) {   // quiet since February
                $sale($d, 50000, $quiet);
            }
            $sale('2026-11-03', 100000, $small);
        });

        $this->scan();

        $dormant = $this->findings(kind: 'dormant_customer');
        $this->assertCount(1, $dormant);
        $this->assertStringContainsString('Quiet Co', $dormant->first()->title);

        $conc = $this->findings(kind: 'revenue_concentration');
        $this->assertCount(1, $conc);
        $this->assertStringContainsString('Big Buyer', $conc->first()->title);
        $this->assertStringContainsString('89%', $conc->first()->title);   // 2,000,000 of 2,250,000 in the last twelve months
    }

    public function test_a_falling_margin_is_flagged_against_the_earlier_months(): void
    {
        foreach ([['2026-05', 100000, 60000], ['2026-06', 100000, 62000], ['2026-07', 100000, 58000], ['2026-08', 100000, 60000]] as [$m, $in, $out]) {
            $this->spend($this->b['bank'], $this->b['sales'], $in, $m.'-05', 'Sale', 'income');
            $this->spend($this->b['bank'], $this->b['food'], $out, $m.'-15');
        }
        $this->spend($this->b['bank'], $this->b['sales'], 100000, '2026-10-05', 'Sale', 'income');
        $this->spend($this->b['bank'], $this->b['food'], 95000, '2026-10-15');

        $this->scan();
        $f = $this->findings(kind: 'margin_drop')->first();

        $this->assertNotNull($f);
        $this->assertStringContainsString('5%', $f->title);
        $this->assertSame('2026-10', $f->period_key);
        $this->assertStringContainsString('40%', $f->body);
    }

    public function test_a_short_runway_is_flagged_by_the_existing_calculator(): void
    {
        $this->b['bank']->forceFill(['opening_balance_cents' => 900000])->save();   // R9,000 in the bank before the spending
        foreach (['2026-09-10', '2026-10-10', '2026-11-10'] as $d) {
            $this->spend($this->b['bank'], $this->b['food'], 200000, $d);          // R2,000 a month: R3,000 is left
        }

        $this->scan();
        $f = $this->findings(kind: 'cash_runway')->first();

        $this->assertNotNull($f);
        $this->assertStringContainsString('ZAR cash would last about', $f->title);
        $this->assertContains($f->severity, ['warning', 'critical']);
    }

    public function test_money_going_out_with_no_cash_is_flagged_in_words_not_as_about_zero_months(): void
    {
        // R3,000 in the bank, R6,000 spent: the account is R3,000 overdrawn. A runway of "0 months" would not be a measurement.
        $this->b['bank']->forceFill(['opening_balance_cents' => 300000])->save();
        foreach (['2026-09-10', '2026-10-10', '2026-11-10'] as $d) {
            $this->spend($this->b['bank'], $this->b['food'], 200000, $d);
        }

        $this->scan();
        $f = $this->findings(kind: 'cash_runway')->first();

        $this->assertNotNull($f);
        $this->assertSame('critical', $f->severity);
        $this->assertStringContainsString('hold no cash while money is going out', $f->title);
        $this->assertStringNotContainsString('0 months', $f->title.$f->body);
    }

    public function test_the_books_and_the_cash_ledger_disagreeing_is_an_attention_item_and_quiet_when_they_agree(): void
    {
        $this->enableAccounting($this->b['context']);
        $tx = $this->spend($this->b['bank'], $this->b['food'], 45000, '2026-11-10', 'Checkers');

        $this->scan();
        $first = $this->findings(kind: 'books_vs_cash');
        $this->assertTrue($first->isNotEmpty(), 'the fixture sets the opening balance without posting it: the check sees that');

        app(\App\Services\Accounting\GlSweeper::class)->run($this->b['context']);
        $this->scan();
        $this->assertSame(0, $this->findings(kind: 'books_vs_cash')->where('status', 'open')->count(), 'once the books have caught up, a ledger that ties raises nothing');

        // A row changed behind the books' back (a restore, a direct edit): the Transactions page and the books now differ.
        \Illuminate\Support\Facades\DB::table('transactions')->where('id', $tx->id)->update(['amount_cents' => 95000]);

        $this->scan();
        $found = $this->findings(kind: 'books_vs_cash')->where('status', 'open');
        $this->assertCount(1, $found);
        $f = $found->first();
        $this->assertSame('warning', $f->severity);
        $this->assertStringContainsString('does not agree with the books', $f->title);
        $this->assertStringContainsString('Cheque', json_encode($f->evidence));
        $this->assertStringContainsString('Transactions', json_encode($f->evidence));

        \Illuminate\Support\Facades\DB::table('transactions')->where('id', $tx->id)->update(['amount_cents' => 45000]);
        $this->scan();
        $this->assertSame(0, $this->findings(kind: 'books_vs_cash')->where('status', 'open')->count(), 'it clears itself when they agree again');
    }

    public function test_the_books_vs_cash_check_says_nothing_when_accounting_is_off(): void
    {
        $this->spend($this->b['bank'], $this->b['food'], 45000, '2026-11-10', 'Checkers');
        $this->scan();

        $this->assertCount(0, $this->findings(kind: 'books_vs_cash'));
    }

    public function test_scanning_twice_changes_nothing_and_a_cleared_condition_is_resolved(): void
    {
        $a = $this->spend($this->b['bank'], $this->b['food'], 45000, '2026-11-10', 'Checkers');
        $b = $this->spend($this->b['bank'], $this->b['food'], 45000, '2026-11-11', 'Checkers');

        $first = $this->scan();
        $second = $this->scan();

        $this->assertCount(1, $first['created']);
        $this->assertCount(0, $second['created']);
        $this->assertSame(1, $second['updated']);
        $this->assertCount(1, $this->findings());

        $b->delete();
        $third = $this->scan();

        $this->assertSame(1, $third['resolved']);
        $this->assertSame('resolved', $this->findings()->first()->status);
        $this->assertSame(0, LedgerContext::run($this->b['context'], fn () => FinanceInsight::query()->visible()->count()));

        // A new pair is a new finding.
        $this->spend($this->b['bank'], $this->b['food'], 45000, '2026-11-11', 'Checkers');
        $this->assertCount(1, $this->scan()['created']);
    }

    public function test_dismissing_survives_later_scans_and_snoozing_hides_until_the_date(): void
    {
        $this->spend($this->b['bank'], $this->b['food'], 45000, '2026-11-10', 'Checkers');
        $this->spend($this->b['bank'], $this->b['food'], 45000, '2026-11-11', 'Checkers');
        $this->spend($this->b['bank'], $this->b['fuel'], 77000, '2026-11-10', 'Engen');
        $this->spend($this->b['bank'], $this->b['fuel'], 77000, '2026-11-12', 'Engen');
        $this->scan();
        [$one, $two] = [$this->findings()[0], $this->findings()[1]];

        $engine = app(InsightEngine::class);
        LedgerContext::run($this->b['context'], function () use ($engine, $one, $two) {
            $engine->dismiss($one, $this->b['owner']);
            $engine->snooze($two, $this->b['owner'], 7);
        });
        $this->scan();

        $this->assertSame('dismissed', $one->fresh()->status);
        $this->assertSame('snoozed', $two->fresh()->status);
        $this->assertSame(0, LedgerContext::run($this->b['context'], fn () => FinanceInsight::query()->visible()->count()));

        $this->fixTime('2026-11-26 09:00:00');
        $this->assertSame(1, LedgerContext::run($this->b['context'], fn () => FinanceInsight::query()->visible()->count()), 'the snooze ran out');
    }

    public function test_only_people_who_may_record_entries_can_dismiss_and_it_is_audited(): void
    {
        $this->spend($this->b['bank'], $this->b['food'], 45000, '2026-11-10', 'Checkers');
        $this->spend($this->b['bank'], $this->b['food'], 45000, '2026-11-11', 'Checkers');
        $this->scan();
        $f = $this->findings()->first();
        $viewer = $this->memberOf($this->b['team'], 'viewer');

        $this->inCompany($viewer, $this->b['team'])->get(route('intel.attention.index'))->assertOk()->assertSee('Possible duplicate')->assertDontSee('It is fine, dismiss');
        $this->post(route('intel.attention.dismiss', $f))->assertForbidden();
        $this->assertSame('open', $f->fresh()->status);

        $this->expectException(AuthorizationException::class);
        LedgerContext::run($this->b['context'], fn () => app(InsightEngine::class)->dismiss($f, $viewer));
    }

    public function test_dismissing_through_the_page_is_audited_and_the_state_line_counts_open_findings(): void
    {
        $this->spend($this->b['bank'], $this->b['food'], 45000, '2026-11-10', 'Checkers');
        $this->spend($this->b['bank'], $this->b['food'], 45000, '2026-11-11', 'Checkers');
        $this->scan();
        $f = $this->findings()->first();

        $items = LedgerContext::run($this->b['context'], fn () => StateLine::for($this->b['context']));
        $this->assertSame('1 thing in the books is worth a look', collect($items)->firstWhere('key', 'attention-findings')['text']);

        $this->inCompany($this->b['owner'], $this->b['team'])->post(route('intel.attention.dismiss', $f))->assertRedirect();

        $this->assertSame('dismissed', $f->fresh()->status);
        $this->assertTrue(FinanceAuditLog::query()->forContext($this->b['context'])->where('subject_type', 'finance_insight')->where('action', 'dismissed')->exists());
        $this->assertNull(collect(LedgerContext::run($this->b['context'], fn () => StateLine::for($this->b['context'])))->firstWhere('key', 'attention-findings'));
    }

    public function test_findings_never_cross_ledgers(): void
    {
        $other = $this->business('Other Ltd');
        $this->spend($other['bank'], $other['food'], 45000, '2026-11-10', 'Othercheckers');
        $this->spend($other['bank'], $other['food'], 45000, '2026-11-11', 'Othercheckers');

        $this->scan($other);

        $this->assertCount(0, $this->findings());
        $this->assertCount(1, $this->findings($other));
        $this->inCompany($this->b['owner'], $this->b['team'])->get(route('intel.attention.index'))->assertOk()->assertDontSee('Othercheckers');
    }
}
