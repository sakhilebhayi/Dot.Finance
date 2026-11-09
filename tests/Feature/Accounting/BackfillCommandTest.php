<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\LedgerSetting;
use App\Models\Transaction;
use App\Services\Accounting\ChartTemplates;
use App\Services\Accounting\GlBackfill;
use App\Support\Ledger\LedgerContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\TestCase;

class BackfillCommandTest extends TestCase
{
    use BuildsCompanyLedgers;
    use RefreshDatabase;

    public function test_the_command_takes_a_ledger_from_dry_run_to_apply_to_enabled(): void
    {
        $user = $this->user();
        $ctx = LedgerContext::personal($user);
        $account = Account::factory()->create(['user_id' => $user->id, 'opening_balance_cents' => 50_000, 'opening_balance_date' => '2026-01-01']);
        Transaction::factory()->create(['account_id' => $account->id, 'amount_cents' => 1_250, 'type' => 'expense', 'date' => '2026-02-02']);
        $key = $ctx->key();

        $this->artisan('finance:gl:backfill', ['--ledger' => $key, '--dry-run' => true])->expectsOutputToContain('no accounting settings')->assertExitCode(1);

        LedgerContext::run($ctx, fn () => app(GlBackfill::class)->prepare($ctx, 'ZAR', 1, ChartTemplates::PERSONAL));

        $this->artisan('finance:gl:backfill', ['--ledger' => $key, '--dry-run' => true])->expectsOutputToContain('dry_run')->assertExitCode(0);
        $this->assertSame(0, JournalEntry::withoutGlobalScopes()->count());

        $this->artisan('finance:gl:backfill', ['--ledger' => $key, '--enable' => true])->assertExitCode(1); // enabling before a verified apply is refused

        $this->artisan('finance:gl:backfill', ['--ledger' => $key, '--apply' => true])->expectsOutputToContain('verified')->assertExitCode(0);
        $this->assertSame(2, JournalEntry::withoutGlobalScopes()->count());
        $this->artisan('finance:gl:backfill', ['--ledger' => $key, '--verify' => true])->expectsOutputToContain('cash_tie_out')->assertExitCode(0);

        $this->artisan('finance:gl:backfill', ['--ledger' => $key, '--apply' => true, '--enable' => true])->expectsOutputToContain('Accounting is enabled')->assertExitCode(0);
        $this->assertNotNull(LedgerSetting::withoutGlobalScopes()->first()->accounting_enabled_at);
        $this->assertSame(2, JournalEntry::withoutGlobalScopes()->count(), 'applying again over a finished backfill posts nothing');
    }

    public function test_the_command_needs_a_named_ledger_and_an_action(): void
    {
        $this->artisan('finance:gl:backfill', ['--dry-run' => true])->assertExitCode(1);

        $user = $this->user();
        $this->artisan('finance:gl:backfill', ['--ledger' => LedgerContext::personal($user)->key()])->assertExitCode(1);
        $this->artisan('finance:gl:backfill', ['--ledger' => 'company:abc', '--dry-run' => true])->assertExitCode(1);
    }

    public function test_the_nightly_schedule_runs_the_sweeper_before_the_verifier(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'finance:gl:'));

        $this->assertCount(2, $events);
        $times = $events->mapWithKeys(fn ($e) => [str_contains($e->command, 'post-pending') ? 'sweep' : 'verify' => $e->expression]);
        $this->assertSame('10 2 * * *', $times['sweep']);
        $this->assertSame('40 2 * * *', $times['verify']);
    }
}
