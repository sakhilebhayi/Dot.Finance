<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalLine;
use App\Models\Accounting\LedgerSetting;
use App\Models\Category;
use App\Services\Accounting\Chart;
use App\Services\Accounting\ChartTemplates;
use App\Services\Accounting\Exceptions\AccountingNotReady;
use App\Services\Accounting\Exceptions\AlreadyReversed;
use App\Services\Accounting\Exceptions\CrossLedger;
use App\Services\Accounting\Exceptions\PeriodClosed;
use App\Services\Accounting\Exceptions\UnbalancedEntry;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\PostingLine;
use App\Services\Accounting\PostingRequest;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\TestCase;

class JournalPosterTest extends TestCase
{
    use BuildsCompanyLedgers;
    use RefreshDatabase;

    private LedgerContext $ctx;

    private JournalPoster $poster;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = LedgerContext::personal($this->user());
        $this->prepare($this->ctx);
        $this->poster = app(JournalPoster::class);
    }

    private function prepare(LedgerContext $ctx): void
    {
        LedgerSetting::create($ctx->ownerAttributes() + ['base_currency' => 'ZAR', 'fiscal_year_start_month' => 1, 'chart_template' => ChartTemplates::COMPANY]);
        app(Chart::class)->ensure($ctx, $ctx->isCompany() ? ChartTemplates::COMPANY : ChartTemplates::PERSONAL);
    }

    private function request(string $date = '2026-03-10', int $cents = 5_000, string $id = 'a', int $version = 1, string $type = 'manual'): PostingRequest
    {
        return new PostingRequest(CarbonImmutable::parse($date), $type, $id, $version, 'Test entry', [
            new PostingLine('cash', debitCents: $cents),
            new PostingLine('sales', creditCents: $cents),
        ]);
    }

    private function postEntry(PostingRequest $r): JournalEntry
    {
        return LedgerContext::run($this->ctx, fn () => $this->poster->post($r)->load(['lines', 'period']));
    }

    public function test_a_balanced_entry_is_posted_with_number_one_period_and_total(): void
    {
        $entry = $this->postEntry($this->request());

        $this->assertSame(1, $entry->entry_number);
        $this->assertSame(5_000, $entry->total_cents);
        $this->assertSame('ZAR', $entry->currency);
        $this->assertSame('posted', $entry->status);
        $this->assertSame('2026-03-01', $entry->period->start_date->toDateString());
        $this->assertSame('2026-03-31', $entry->period->end_date->toDateString());
        $this->assertCount(2, $entry->lines);
        $this->assertSame(5_000, (int) $entry->lines->sum('debit_cents'));
        $this->assertSame(5_000, (int) $entry->lines->sum('credit_cents'));
        $this->assertSame('JE-000001', $entry->reference());
    }

    public function test_numbers_are_sequential_and_gap_free_even_after_a_refused_post(): void
    {
        $this->postEntry($this->request(id: 'a'));

        try {
            $this->postEntry(new PostingRequest(CarbonImmutable::parse('2026-03-11'), 'manual', 'bad', 1, 'Unbalanced', [new PostingLine('cash', debitCents: 10), new PostingLine('sales', creditCents: 9)]));
            $this->fail('an unbalanced entry must be refused');
        } catch (UnbalancedEntry) {
        }

        $this->postEntry($this->request(id: 'b'));
        $this->postEntry($this->request(id: 'c'));

        $numbers = JournalEntry::withoutGlobalScopes()->orderBy('entry_number')->pluck('entry_number')->all();
        $this->assertSame([1, 2, 3], $numbers);
        $this->assertSame(4, (int) LedgerSetting::withoutGlobalScopes()->first()->next_entry_number);
    }

    public function test_an_unbalanced_or_one_line_entry_is_refused_and_leaves_no_trace(): void
    {
        foreach ([
            [new PostingLine('cash', debitCents: 100), new PostingLine('sales', creditCents: 99)],
            [new PostingLine('cash', debitCents: 100)],
        ] as $lines) {
            try {
                $this->postEntry(new PostingRequest(CarbonImmutable::parse('2026-03-10'), 'manual', uniqid(), 1, 'x', $lines));
                $this->fail('expected UnbalancedEntry');
            } catch (UnbalancedEntry) {
            }
        }

        $this->assertSame(0, JournalEntry::withoutGlobalScopes()->count());
        $this->assertSame(0, JournalLine::withoutGlobalScopes()->count());
    }

    public function test_a_posting_line_must_have_exactly_one_non_zero_side(): void
    {
        foreach ([[0, 0], [5, 5], [-1, 0]] as [$d, $c]) {
            try {
                new PostingLine('cash', $d, $c);
                $this->fail("debit {$d} credit {$c} should be refused");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_posting_the_same_source_twice_returns_the_first_entry(): void
    {
        $first = $this->postEntry($this->request(id: 'same'));
        $second = $this->postEntry($this->request(id: 'same', cents: 9_999)); // same key: the retry is a no-op, not an overwrite

        $this->assertSame($first->id, $second->id);
        $this->assertSame(5_000, $second->total_cents);
        $this->assertSame(1, JournalEntry::withoutGlobalScopes()->count());
        $this->assertSame(2, JournalLine::withoutGlobalScopes()->count());
    }

    public function test_the_same_source_id_in_another_ledger_is_a_different_entry(): void
    {
        $other = LedgerContext::personal($this->user());
        $this->prepare($other);

        $mine = $this->postEntry($this->request(id: 'shared'));
        $theirs = LedgerContext::run($other, fn () => $this->poster->post($this->request(id: 'shared')));

        $this->assertNotSame($mine->id, $theirs->id);
        $this->assertSame(1, $theirs->entry_number, 'each ledger numbers from 1');
    }

    public function test_a_closed_period_refuses_a_post_but_the_day_after_is_open(): void
    {
        LedgerSetting::withoutGlobalScopes()->where('user_id', $this->ctx->userId)->update(['books_closed_through' => '2026-03-31']);

        try {
            $this->postEntry($this->request('2026-03-31'));
            $this->fail('the last closed day must be refused');
        } catch (PeriodClosed) {
        }
        $this->assertSame(0, JournalEntry::withoutGlobalScopes()->count());

        $this->assertSame('2026-04-01', $this->postEntry($this->request('2026-04-01'))->entry_date->toDateString());
    }

    public function test_a_ledger_without_settings_cannot_be_posted_to(): void
    {
        $bare = LedgerContext::personal($this->user());

        $this->expectException(AccountingNotReady::class);
        LedgerContext::run($bare, fn () => $this->poster->post($this->request()));
    }

    public function test_posting_with_no_ledger_context_is_refused(): void
    {
        $this->expectException(CrossLedger::class);
        $this->poster->post($this->request());
    }

    public function test_an_account_from_another_ledger_is_refused(): void
    {
        $other = LedgerContext::personal($this->user());
        $this->prepare($other);
        $foreignCash = LedgerContext::run($other, fn () => app(Chart::class)->system('cash', $other)->id);

        $this->expectException(CrossLedger::class);
        $this->postEntry(new PostingRequest(CarbonImmutable::parse('2026-03-10'), 'manual', 'x', 1, 'Smuggled', [
            new PostingLine($foreignCash, debitCents: 100),
            new PostingLine('sales', creditCents: 100),
        ]));
    }

    public function test_a_cash_account_or_category_from_another_ledger_is_refused(): void
    {
        $stranger = $this->user();
        $theirAccount = Account::factory()->create(['user_id' => $stranger->id]);
        $theirCategory = Category::factory()->create(['user_id' => $stranger->id]);

        foreach ([['cash', $theirAccount->id, null], ['cat', null, $theirCategory->id]] as [$label, $cashId, $categoryId]) {
            try {
                $this->postEntry(new PostingRequest(CarbonImmutable::parse('2026-03-10'), 'manual', uniqid(), 1, 'x', [
                    new PostingLine('cash', debitCents: 100, cashAccountId: $cashId, categoryId: $categoryId),
                    new PostingLine('sales', creditCents: 100),
                ]));
                $this->fail('expected CrossLedger for '.$label);
            } catch (CrossLedger) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, JournalEntry::withoutGlobalScopes()->count());
    }

    public function test_inactive_and_report_only_accounts_cannot_be_posted_to(): void
    {
        $account = LedgerContext::run($this->ctx, fn () => app(Chart::class)->create($this->ctx, ['code' => '6999', 'name' => 'Old', 'class' => 'expense']));
        $account->update(['is_active' => false]);

        $this->expectException(UnbalancedEntry::class);
        $this->postEntry(new PostingRequest(CarbonImmutable::parse('2026-03-10'), 'manual', 'x', 1, 'x', [
            new PostingLine($account->id, debitCents: 100),
            new PostingLine('cash', creditCents: 100),
        ]));
    }

    public function test_current_year_earnings_is_a_report_line_and_not_postable(): void
    {
        $this->expectException(UnbalancedEntry::class);
        $this->postEntry(new PostingRequest(CarbonImmutable::parse('2026-03-10'), 'manual', 'x', 1, 'x', [
            new PostingLine('current_year_earnings', debitCents: 100),
            new PostingLine('cash', creditCents: 100),
        ]));
    }

    public function test_reverse_writes_the_mirror_image_links_both_ways_and_marks_the_original(): void
    {
        $entry = $this->postEntry($this->request());

        $reversal = LedgerContext::run($this->ctx, fn () => $this->poster->reverse($entry, 'Entered in error'));
        $entry->refresh();

        $this->assertSame('reversed', $entry->status);
        $this->assertSame($reversal->id, $entry->reversed_by_entry_id);
        $this->assertSame($entry->id, $reversal->reverses_entry_id);
        $this->assertSame('reversal', $reversal->source_type);
        $this->assertSame(2, $reversal->entry_number);
        $this->assertStringContainsString('Entered in error', $reversal->memo);

        $net = DB::table('journal_lines')->selectRaw('gl_account_id, SUM(debit_cents) - SUM(credit_cents) AS net')->groupBy('gl_account_id')->pluck('net')->map(fn ($n) => (int) $n);
        $this->assertSame([0, 0], $net->values()->all(), 'the entry and its reversal cancel on every account');
    }

    public function test_an_entry_is_reversed_once_and_a_reversal_is_never_reversed(): void
    {
        $entry = $this->postEntry($this->request());
        $reversal = LedgerContext::run($this->ctx, fn () => $this->poster->reverse($entry, 'once'));

        foreach ([$entry, $reversal] as $target) {
            try {
                LedgerContext::run($this->ctx, fn () => $this->poster->reverse($target, 'again'));
                $this->fail('expected AlreadyReversed');
            } catch (AlreadyReversed) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(2, JournalEntry::withoutGlobalScopes()->count());
    }

    public function test_reversing_into_a_closed_period_is_refused_unless_dated_in_an_open_one(): void
    {
        $entry = $this->postEntry($this->request('2026-03-10'));
        LedgerSetting::withoutGlobalScopes()->update(['books_closed_through' => '2026-03-31']);

        try {
            LedgerContext::run($this->ctx, fn () => $this->poster->reverse($entry, 'late'));
            $this->fail('expected PeriodClosed');
        } catch (PeriodClosed) {
        }

        $reversal = LedgerContext::run($this->ctx, fn () => $this->poster->reverse($entry, 'late', CarbonImmutable::parse('2026-04-02')));
        $this->assertSame('2026-04-02', $reversal->entry_date->toDateString());
    }

    public function test_another_ledgers_entry_cannot_be_reversed(): void
    {
        $other = LedgerContext::personal($this->user());
        $this->prepare($other);
        $theirs = LedgerContext::run($other, fn () => $this->poster->post($this->request()));

        $this->expectException(CrossLedger::class);
        LedgerContext::run($this->ctx, fn () => $this->poster->reverse($theirs, 'mine now'));
    }

    public function test_repost_reverses_the_live_version_and_posts_the_next_one_atomically(): void
    {
        $v1 = $this->postEntry($this->request(id: 'src', cents: 1_000, type: 'transaction'));

        $v2 = LedgerContext::run($this->ctx, fn () => $this->poster->repost('transaction', 'src', $this->request(id: 'src', cents: 1_500, type: 'transaction')));

        $this->assertSame(2, $v2->source_version);
        $this->assertSame('reversed', $v1->refresh()->status);
        $this->assertSame(3, JournalEntry::withoutGlobalScopes()->count()); // v1, its reversal, v2
        $live = LedgerContext::run($this->ctx, fn () => $this->poster->liveEntry($this->ctx, 'transaction', 'src'));
        $this->assertSame($v2->id, $live->id);

        $cash = Chart::class;
        $cashId = LedgerContext::run($this->ctx, fn () => app($cash)->system('cash', $this->ctx)->id);
        $this->assertSame(1_500, (int) DB::table('journal_lines')->where('gl_account_id', $cashId)->selectRaw('SUM(debit_cents) - SUM(credit_cents) AS n')->value('n'));
    }

    public function test_a_failed_repost_leaves_the_original_intact(): void
    {
        $v1 = $this->postEntry($this->request(id: 'src', type: 'transaction'));

        try {
            LedgerContext::run($this->ctx, fn () => $this->poster->repost('transaction', 'src', new PostingRequest(CarbonImmutable::parse('2026-03-10'), 'transaction', 'src', 2, 'bad', [
                new PostingLine('cash', debitCents: 5), new PostingLine('sales', creditCents: 4),
            ])));
            $this->fail('expected UnbalancedEntry');
        } catch (UnbalancedEntry) {
        }

        $this->assertSame('posted', $v1->refresh()->status);
        $this->assertSame(1, JournalEntry::withoutGlobalScopes()->count());
    }

    public function test_the_hash_chain_links_each_entry_to_the_one_before(): void
    {
        $a = $this->postEntry($this->request(id: 'a'));
        $b = $this->postEntry($this->request(id: 'b'));

        $this->assertNull($a->prev_hash);
        $this->assertSame($a->entry_hash, $b->prev_hash);
        $this->assertSame(64, strlen($b->entry_hash));
        $this->assertNotSame($a->entry_hash, $b->entry_hash);
    }

    // ── append-only ─────────────────────────────────────────────────────────

    public function test_the_models_refuse_update_and_delete(): void
    {
        $entry = $this->postEntry($this->request());

        foreach ([fn () => $entry->update(['memo' => 'changed']), fn () => $entry->delete(), fn () => $entry->lines->first()->update(['debit_cents' => 1]), fn () => $entry->lines->first()->delete()] as $attempt) {
            try {
                $attempt();
                $this->fail('a journal row must refuse the change');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_database_refuses_update_and_delete_of_journal_rows(): void
    {
        $entry = $this->postEntry($this->request());
        $lineId = $entry->lines->first()->id;

        $attempts = [
            fn () => DB::table('journal_lines')->where('id', $lineId)->update(['debit_cents' => 1, 'credit_cents' => 0]),
            fn () => DB::table('journal_lines')->where('id', $lineId)->delete(),
            fn () => DB::table('journal_entries')->where('id', $entry->id)->update(['memo' => 'rewritten']),
            fn () => DB::table('journal_entries')->where('id', $entry->id)->update(['total_cents' => 1]),
            fn () => DB::table('journal_entries')->where('id', $entry->id)->update(['status' => 'reversed']), // without the link
            fn () => DB::table('journal_entries')->where('id', $entry->id)->delete(),
        ];

        foreach ($attempts as $i => $attempt) {
            DB::beginTransaction(); // a refused statement aborts a PostgreSQL transaction; isolate each attempt
            try {
                $attempt();
                $this->fail("attempt {$i} should have been refused by the database");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            } finally {
                DB::rollBack();
            }
        }

        $this->assertSame('posted', JournalEntry::withoutGlobalScopes()->find($entry->id)->status);
    }

    public function test_the_database_refuses_a_line_that_is_not_exactly_one_sided(): void
    {
        $entry = $this->postEntry($this->request());
        $row = fn (array $override) => array_merge([
            'user_id' => $this->ctx->userId, 'team_id' => null, 'journal_entry_id' => $entry->id, 'line_no' => 9, 'entry_date' => '2026-03-10',
            'gl_account_id' => $entry->lines->first()->gl_account_id, 'debit_cents' => 5, 'credit_cents' => 0, 'created_at' => now(),
        ], $override);

        foreach ([['debit_cents' => 0, 'credit_cents' => 0], ['debit_cents' => 5, 'credit_cents' => 5], ['txn_currency' => 'USD'], ['txn_currency' => 'usd', 'txn_amount_cents' => 5]] as $bad) {
            DB::beginTransaction();
            try {
                DB::table('journal_lines')->insert($row($bad));
                $this->fail('expected a CHECK failure for '.json_encode($bad));
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            } finally {
                DB::rollBack();
            }
        }
    }

    public function test_postgres_refuses_to_commit_an_entry_that_does_not_balance(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The deferred balance trigger exists on PostgreSQL only (SQLite relies on the poster and finance:gl:verify).');
        }

        $entry = $this->postEntry($this->request());
        $period = $entry->period_id;
        $cash = $entry->lines->first()->gl_account_id;

        $insertEntry = fn (int $n) => DB::table('journal_entries')->insertGetId([
            'user_id' => $this->ctx->userId, 'entry_number' => $n, 'entry_date' => '2026-03-12', 'period_id' => $period, 'status' => 'posted',
            'source_type' => 'manual', 'source_id' => "raw-{$n}", 'source_version' => 1, 'memo' => 'raw', 'currency' => 'ZAR', 'total_cents' => 100,
            'posted_at' => now(), 'entry_hash' => str_repeat('a', 64),
        ]);
        $line = fn (int $entryId, int $no, int $d, int $c) => DB::table('journal_lines')->insert([
            'user_id' => $this->ctx->userId, 'journal_entry_id' => $entryId, 'line_no' => $no, 'entry_date' => '2026-03-12',
            'gl_account_id' => $cash, 'debit_cents' => $d, 'credit_cents' => $c, 'created_at' => now(),
        ]);

        // Unbalanced: debits 100, credits 90.
        DB::beginTransaction();
        $id = $insertEntry(50);
        $line($id, 1, 100, 0);
        $line($id, 2, 0, 90);
        try {
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->fail('the unbalanced entry should be refused at commit');
        } catch (QueryException $e) {
            $this->assertStringContainsString('does not balance', $e->getMessage());
        } finally {
            DB::rollBack();
        }

        // An entry with a single line is refused too.
        DB::beginTransaction();
        $id = $insertEntry(51);
        $line($id, 1, 100, 0);
        try {
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->fail('a one-line entry should be refused');
        } catch (QueryException $e) {
            $this->assertStringContainsString('does not balance', $e->getMessage());
        } finally {
            DB::rollBack();
        }

        // A balanced one passes.
        DB::beginTransaction();
        $id = $insertEntry(52);
        $line($id, 1, 100, 0);
        $line($id, 2, 0, 100);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::rollBack();
        $this->assertTrue(true);
    }

    public function test_every_chart_system_key_exists_in_both_templates(): void
    {
        foreach ([ChartTemplates::COMPANY, ChartTemplates::PERSONAL] as $template) {
            $keys = collect(ChartTemplates::rows($template))->pluck(4)->filter()->all();
            $this->assertSame([], array_values(array_diff(ChartTemplates::SYSTEM_KEYS, $keys)), "{$template} is missing system keys");
            $this->assertSame(count($keys), count(array_unique($keys)), "{$template} repeats a system key");

            $codes = collect(ChartTemplates::rows($template))->pluck(0);
            $this->assertSame($codes->count(), $codes->unique()->count(), "{$template} repeats an account code");
        }
    }

    public function test_the_chart_is_idempotent_and_never_drops_a_system_key(): void
    {
        $chart = app(Chart::class);
        $before = GlAccount::withoutGlobalScopes()->count();

        $this->assertSame(0, LedgerContext::run($this->ctx, fn () => $chart->ensure($this->ctx, ChartTemplates::PERSONAL)));
        $this->assertSame($before, GlAccount::withoutGlobalScopes()->count());
        $this->assertTrue(LedgerContext::run($this->ctx, fn () => $chart->isComplete($this->ctx)));
    }
}
