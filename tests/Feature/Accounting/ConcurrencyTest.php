<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\Accounting\JournalEntry;
use App\Models\RecurringRule;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\GlVerifier;
use App\Support\Ledger\LedgerContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsAccounting;
use Tests\TestCase;

/**
 * Real processes against real PostgreSQL row locks: SQLite ignores FOR UPDATE and has one writer at a time, so
 * these skip there. The test commits its own data (RefreshDatabase's wrapping transaction would hide it from the
 * workers) and truncates everything afterwards.
 */
class ConcurrencyTest extends TestCase
{
    use BuildsAccounting;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Concurrent locking is exercised on PostgreSQL only.');
        }

        Artisan::call('migrate', ['--force' => true]);
        $this->wipe();
    }

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->wipe();
        }
        parent::tearDown();
    }

    private function wipe(): void
    {
        // TRUNCATE does not fire the append-only row triggers, and CASCADE follows every foreign key.
        DB::statement('TRUNCATE TABLE users, teams RESTART IDENTITY CASCADE');
    }

    /** @param list<list<string>> $commands */
    private function runTogether(array $commands): array
    {
        $db = config('database.connections.pgsql');
        $env = array_merge(getenv(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'],
            'DB_DATABASE' => (string) $db['database'], 'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) ($db['password'] ?? ''),
            'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null',
        ]);

        $startAt = microtime(true) + 1.5;
        $processes = [];
        foreach ($commands as $i => $args) {
            $cmd = array_merge([PHP_BINARY, base_path('tests/Support/concurrent_worker.php')], $args, [(string) $startAt]);
            $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            $this->assertIsResource($process);
            $processes[$i] = [$process, $pipes];
        }

        $results = [];
        foreach ($processes as $i => [$process, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            $code = proc_close($process);
            $this->assertSame(0, $code, "worker {$i} failed: {$err}{$out}");
            $results[$i] = trim($out);
        }

        return $results;
    }

    public function test_simultaneous_posters_produce_gap_free_unique_numbers_and_one_entry_per_source_key(): void
    {
        $user = User::factory()->create();
        $ctx = LedgerContext::personal($user);
        $this->enableAccounting($ctx);

        $workers = 4;
        $each = 15;
        $this->runTogether(array_map(fn ($w) => ['post', (string) $user->id, (string) $w, (string) $each], range(1, $workers)));

        $numbers = JournalEntry::withoutGlobalScopes()->where('user_id', $user->id)->orderBy('entry_number')->pluck('entry_number')->all();
        $expected = $workers * $each + $each; // each worker's own keys, plus one entry per shared key no matter how many tried it

        $this->assertCount($expected, $numbers);
        $this->assertSame(range(1, $expected), $numbers, 'numbers are 1..N with no gap and no duplicate');
        $this->assertSame($each, JournalEntry::withoutGlobalScopes()->where('user_id', $user->id)->where('source_id', 'like', 'shared-%')->count());

        $report = LedgerContext::run($ctx, fn () => app(GlVerifier::class)->verify($ctx));
        $this->assertTrue($report['ok'], json_encode(collect($report['checks'])->where('ok', false)->values()));
        $this->assertSame(0, DB::table('journal_lines')->selectRaw('journal_entry_id')->groupBy('journal_entry_id')->havingRaw('SUM(debit_cents) <> SUM(credit_cents)')->get()->count());
    }

    public function test_two_schedulers_running_at_once_generate_each_occurrence_exactly_once(): void
    {
        $user = User::factory()->create();
        $ctx = LedgerContext::personal($user);
        $account = Account::factory()->create(['user_id' => $user->id, 'opening_balance_cents' => 100_000, 'opening_balance_date' => '2025-01-01']);
        $this->enableAccounting($ctx);
        $rule = RecurringRule::factory()->create([
            'user_id' => $user->id, 'account_id' => $account->id, 'amount_cents' => 1_000, 'type' => 'expense', 'recurrence_type' => 'daily', 'recurrence_interval' => 1,
            'start_date' => now()->subDays(11)->toDateString(), 'next_occurrence_date' => now()->subDays(11)->toDateString(),
        ]);

        $out = $this->runTogether([['catchup', (string) $user->id, (string) $rule->id], ['catchup', (string) $user->id, (string) $rule->id], ['catchup', (string) $user->id, (string) $rule->id]]);

        $this->assertSame(12, array_sum(array_map('intval', $out)), 'twelve days are due; however many runners race, twelve rows are written: '.json_encode($out));
        $this->assertSame(12, Transaction::withoutGlobalScopes()->where('recurring_rule_id', $rule->id)->count());
        $this->assertSame(0, DB::table('transactions')->select('date')->where('recurring_rule_id', $rule->id)->groupBy('date')->havingRaw('COUNT(*) > 1')->get()->count(), 'no date twice');
        $this->assertSame(12, JournalEntry::withoutGlobalScopes()->where('user_id', $user->id)->where('source_type', 'transaction')->count());
    }
}
