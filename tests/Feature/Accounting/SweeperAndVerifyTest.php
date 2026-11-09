<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\Accounting\JournalEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\GlSweeper;
use App\Services\Accounting\GlSync;
use App\Services\Accounting\GlVerifier;
use App\Services\Accounting\Periods;
use App\Services\LedgerReconciler;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsAccounting;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\Concerns\LiftsJournalGuards;
use Tests\TestCase;

class SweeperAndVerifyTest extends TestCase
{
    use BuildsAccounting;
    use BuildsCompanyLedgers;
    use LiftsJournalGuards;
    use RefreshDatabase;

    private User $user;

    private LedgerContext $ctx;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->user();
        $this->ctx = $this->personalContext($this->user);
        $this->bank = Account::factory()->create(['user_id' => $this->user->id, 'opening_balance_cents' => 100_000, 'opening_balance_date' => '2026-01-01']);
        Transaction::factory()->create(['account_id' => $this->bank->id, 'type' => 'expense', 'amount_cents' => 1_000, 'date' => '2026-02-01']);
        $this->enableAccounting($this->ctx);
    }

    private function verify(): array
    {
        return LedgerContext::run($this->ctx, fn () => app(GlVerifier::class)->verify($this->ctx));
    }

    private function failed(array $report): array
    {
        return collect($report['checks'])->where('ok', false)->pluck('code')->values()->all();
    }

    public function test_a_clean_ledger_verifies_and_the_command_exits_zero(): void
    {
        $this->assertTrue($this->verify()['ok']);
        $this->artisan('finance:gl:verify', ['--ledger' => $this->ctx->key()])->expectsOutputToContain('OK')->assertExitCode(0);
    }

    public function test_the_verify_command_prints_json_and_rejects_a_malformed_ledger(): void
    {
        $this->artisan('finance:gl:verify', ['--ledger' => $this->ctx->key(), '--json' => true])->expectsOutputToContain('"ok": true')->assertExitCode(0);
        $this->artisan('finance:gl:verify', ['--ledger' => 'nonsense'])->assertExitCode(1);
        $this->artisan('finance:gl:verify', ['--ledger' => 'company:999999'])->assertExitCode(1);
    }

    public function test_a_row_written_around_the_app_is_found_and_the_sweeper_posts_it(): void
    {
        // A raw insert bypasses every controller and service, as a restore or a script would.
        $id = DB::table('transactions')->insertGetId([
            'account_id' => $this->bank->id, 'user_id' => $this->user->id, 'description' => 'Raw', 'amount' => '12.34', 'amount_cents' => 1_234,
            'currency' => 'ZAR', 'type' => 'expense', 'date' => '2026-03-01', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $report = $this->verify();
        $this->assertFalse($report['ok']);
        $this->assertEqualsCanonicalizing(['cash_tie_out', 'pending_sources'], $this->failed($report));
        $this->artisan('finance:gl:verify', ['--ledger' => $this->ctx->key()])->assertExitCode(1);

        $this->artisan('finance:gl:post-pending', ['--ledger' => $this->ctx->key(), '--dry-run' => true])->expectsOutputToContain('1 transaction(s)')->assertExitCode(0);
        $this->artisan('finance:gl:post-pending', ['--ledger' => $this->ctx->key()])->expectsOutputToContain('posted 1')->assertExitCode(0);

        $this->assertTrue($this->verify()['ok']);
        $this->assertSame(1, JournalEntry::withoutGlobalScopes()->where('source_type', 'transaction')->where('source_id', (string) $id)->where('status', 'posted')->count());
    }

    public function test_the_sweeper_is_idempotent(): void
    {
        DB::table('transactions')->insert([
            'account_id' => $this->bank->id, 'user_id' => $this->user->id, 'description' => 'Raw', 'amount' => '1.00', 'amount_cents' => 100,
            'currency' => 'ZAR', 'type' => 'expense', 'date' => '2026-03-01', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $first = app(GlSweeper::class)->run($this->ctx);
        $entries = JournalEntry::withoutGlobalScopes()->count();
        $second = app(GlSweeper::class)->run($this->ctx);

        $this->assertSame(1, $first['posted']);
        $this->assertSame(0, $second['posted'] + $second['reposted'] + $second['reversed']);
        $this->assertSame($entries, JournalEntry::withoutGlobalScopes()->count());
    }

    public function test_a_row_deleted_around_the_app_is_reversed(): void
    {
        $tx = Transaction::query()->forContext($this->ctx)->firstOrFail();
        DB::table('transactions')->where('id', $tx->id)->update(['deleted_at' => now()]);

        $this->assertFalse($this->verify()['ok']);
        app(GlSweeper::class)->run($this->ctx);

        $this->assertTrue($this->verify()['ok']);
        $this->assertSame('reversed', JournalEntry::withoutGlobalScopes()->where('source_type', 'transaction')->first()->status);
    }

    public function test_content_drift_is_caught_by_the_tie_out_and_repaired_by_a_resync(): void
    {
        $tx = Transaction::query()->forContext($this->ctx)->firstOrFail();
        DB::table('transactions')->where('id', $tx->id)->update(['amount_cents' => 2_500, 'amount' => '25.00']);

        $this->assertContains('cash_tie_out', $this->failed($this->verify()));

        $result = app(GlSweeper::class)->run($this->ctx);

        $this->assertSame(1, $result['reposted']);
        $this->assertTrue($this->verify()['ok']);
        $this->assertSame(2_500, JournalEntry::withoutGlobalScopes()->where('source_type', 'transaction')->where('status', 'posted')->first()->total_cents);
    }

    public function test_the_reconciler_names_a_cash_tie_out_failure_and_pending_rows_once_accounting_is_on(): void
    {
        DB::table('transactions')->insert([
            'account_id' => $this->bank->id, 'user_id' => $this->user->id, 'description' => 'Raw', 'amount' => '9.00', 'amount_cents' => 900,
            'currency' => 'ZAR', 'type' => 'expense', 'date' => '2026-03-01', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $report = (new LedgerReconciler)->run($this->ctx);
        $codes = array_column($report['findings'], 'code');

        $this->assertContains('gl_cash_tieout', $codes);
        $this->assertContains('gl_pending', $codes);
        $this->assertNotSame([], LedgerReconciler::problems($report));
    }

    // ── tampering that the triggers normally prevent: prove the verifier would still notice ──

    public function test_verify_notices_an_unbalanced_entry(): void
    {
        $this->liftJournalGuards();
        DB::table('journal_lines')->where('id', DB::table('journal_lines')->min('id'))->update(['debit_cents' => DB::raw('debit_cents + 1'), 'credit_cents' => DB::raw('credit_cents')]);

        $report = $this->verify();
        $this->assertContains('entries_balance', $this->failed($report));
        $this->assertContains('trial_balance', $this->failed($report));
    }

    public function test_verify_notices_a_gap_in_the_entry_numbers(): void
    {
        $this->liftJournalGuards();
        $first = JournalEntry::withoutGlobalScopes()->orderBy('entry_number')->first();
        DB::table('journal_lines')->where('journal_entry_id', $first->id)->delete();
        DB::table('journal_entries')->where('id', $first->id)->delete();

        $this->assertContains('entry_numbers', $this->failed($this->verify()));
    }

    public function test_verify_notices_a_tampered_hash_chain(): void
    {
        $this->liftJournalGuards();
        DB::table('journal_entries')->where('entry_number', 2)->update(['prev_hash' => str_repeat('0', 64)]);

        $this->assertContains('entry_numbers', $this->failed($this->verify()));
    }

    public function test_verify_notices_a_line_that_points_at_another_ledgers_account(): void
    {
        $stranger = $this->user();
        $theirAccount = Account::factory()->create(['user_id' => $stranger->id]);
        $this->liftJournalGuards();
        DB::table('journal_lines')->where('id', DB::table('journal_lines')->min('id'))->update(['cash_account_id' => $theirAccount->id]);

        $this->assertContains('ledger_isolation', $this->failed($this->verify()));
    }

    public function test_verify_notices_a_broken_reversal_link(): void
    {
        $tx = Transaction::query()->forContext($this->ctx)->firstOrFail();
        $tx->delete();
        LedgerContext::run($this->ctx, fn () => app(GlSync::class)->transaction($tx));
        $this->assertTrue($this->verify()['ok']);

        $this->liftJournalGuards();
        DB::table('journal_entries')->where('source_type', 'reversal')->update(['reverses_entry_id' => null]);

        $this->assertContains('reversals', $this->failed($this->verify()));
    }

    public function test_verify_notices_an_entry_posted_into_a_period_after_it_was_closed(): void
    {
        LedgerContext::run($this->ctx, fn () => app(Periods::class)->closeThrough($this->ctx, CarbonImmutable::parse('2026-02-28'), $this->user));
        $this->assertTrue($this->verify()['ok']);

        $this->liftJournalGuards();
        DB::table('accounting_periods')->where('end_date', '2026-02-28')->update(['closed_at' => now()->subDay()]);

        $this->assertContains('periods', $this->failed($this->verify()));
    }
}
