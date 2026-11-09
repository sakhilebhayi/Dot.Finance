<?php

namespace Tests\Feature\Bank;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Real contention, not a simulation: separate PHP processes with their own database connections race for the same ledger
 * entry, and for the same session. Exactly one match wins; exactly one completion wins.
 *
 * On SQLite this runs against a throwaway file (one writer at a time is the contention). On PostgreSQL it exercises the row
 * locks themselves, which is the case that matters in production (phpunit.pgsql.xml).
 *
 * No RefreshDatabase: other processes cannot see a transaction that is still open.
 */
class BankConcurrencyTest extends TestCase
{
    private ?string $file = null;

    private ?int $userId = null;

    /** @var array<string, string> */
    private array $env = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is not available.');
        }

        if (DB::getDriverName() === 'sqlite') {
            $dir = storage_path('framework/testing');
            @mkdir($dir, 0775, true);
            $this->file = $dir.'/bank-'.bin2hex(random_bytes(6)).'.sqlite';
            touch($this->file);
            $this->env = ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->file, 'DB_URL' => ''];
            $this->child(['php', 'artisan', 'migrate', '--force', '--no-interaction']);
        } else {
            Artisan::call('migrate', ['--force' => true]);
            $this->env = [];
        }
    }

    protected function tearDown(): void
    {
        if ($this->file !== null) {
            @unlink($this->file);
        } elseif ($this->userId !== null) {
            // Bank history is append-only on purpose; a throwaway test database is the one place it is cleared, with the guards lifted.
            $id = $this->userId;
            DB::table('transactions')->where('user_id', $id)->update(['bank_match_id' => null, 'reconciliation_id' => null]);
            DB::table('bank_statement_lines')->where('user_id', $id)->update(['bank_match_id' => null, 'reconciliation_id' => null]);
            DB::table('bank_matches')->where('user_id', $id)->delete();
            foreach (['bank_reconciliation_events', 'bank_adjustments'] as $table) {
                DB::statement("ALTER TABLE {$table} DISABLE TRIGGER USER");
                DB::table($table)->where('user_id', $id)->delete();
                DB::statement("ALTER TABLE {$table} ENABLE TRIGGER USER");
            }
            DB::table('bank_reconciliations')->where('user_id', $id)->delete();
            DB::table('bank_statement_lines')->where('user_id', $id)->delete();
            DB::table('bank_statements')->where('user_id', $id)->delete();
            DB::table('transactions')->where('user_id', $id)->delete();
            DB::table('finance_audit_log')->where('user_id', $id)->exists() && DB::statement('ALTER TABLE finance_audit_log DISABLE TRIGGER USER');
            DB::table('finance_audit_log')->where('user_id', $id)->delete();
            DB::statement('ALTER TABLE finance_audit_log ENABLE TRIGGER USER');
            DB::table('accounts')->where('user_id', $id)->delete();
            DB::table('users')->where('id', $id)->delete();
        }

        parent::tearDown();
    }

    /** @param  list<string>  $command */
    private function child(array $command): string
    {
        $process = new Process($command, base_path(), $this->env + ['APP_ENV' => 'testing'], null, 120);
        $process->mustRun();

        return $process->getOutput();
    }

    /** @return array{user: int, account: int, session: int, entry: int, line: int} */
    private function setUpLedger(int $lines): array
    {
        [$user, $account, $session, $entry, $line] = array_map('intval', explode(' ', trim($this->child(['php', 'tests/Support/bank_worker.php', 'setup', (string) $lines]))));
        $this->userId = $user;

        return compact('user', 'account', 'session', 'entry', 'line');
    }

    /** @param  list<list<string>>  $commands @return list<string> each worker's one-line answer */
    private function race(array $commands): array
    {
        $processes = [];
        foreach ($commands as $command) {
            $p = new Process(['php', 'tests/Support/bank_worker.php', ...$command], base_path(), $this->env + ['APP_ENV' => 'testing'], null, 180);
            $p->start();
            $processes[] = $p;
        }

        $answers = [];
        foreach ($processes as $p) {
            $p->wait();
            $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput().$p->getOutput());
            $answers[] = trim($p->getOutput());
        }

        return $answers;
    }

    public function test_four_processes_racing_to_match_one_entry_produce_exactly_one_match(): void
    {
        $x = $this->setUpLedger(4);

        $answers = $this->race(array_map(fn (int $i) => ['match', (string) $x['user'], (string) $x['session'], (string) $x['entry'], (string) ($x['line'] + $i), '150'], range(0, 3)));

        sort($answers);
        $this->assertSame(['OK', 'REFUSED:already_matched', 'REFUSED:already_matched', 'REFUSED:already_matched'], $answers);
        $this->assertSame(1, $this->rows('bank_matches')->where('user_id', $x['user'])->count());
        $this->assertSame(1, $this->rows('transactions')->where('user_id', $x['user'])->whereNotNull('bank_match_id')->count());
        $this->assertSame(1, $this->rows('bank_statement_lines')->where('user_id', $x['user'])->whereNotNull('bank_match_id')->count());
    }

    public function test_two_processes_completing_the_same_session_complete_it_once(): void
    {
        $x = $this->setUpLedger(1);
        $this->race([['match', (string) $x['user'], (string) $x['session'], (string) $x['entry'], (string) $x['line'], '0']]);

        $answers = $this->race([['complete', (string) $x['user'], (string) $x['session'], '200'], ['complete', (string) $x['user'], (string) $x['session'], '200']]);

        sort($answers);
        $this->assertSame(['OK', 'REFUSED:not_draft'], $answers);
        $this->assertSame(1, $this->rows('bank_reconciliation_events')->where('user_id', $x['user'])->where('event', 'completed')->count());
        $this->assertSame('completed', $this->rows('bank_reconciliations')->where('id', $x['session'])->value('status'));
    }

    /** The table as the child processes see it: on SQLite their throwaway file, on PostgreSQL the shared test database. */
    private function rows(string $table): Builder
    {
        if ($this->file === null) {
            return DB::table($table);
        }

        config(['database.connections.bankfile' => ['driver' => 'sqlite', 'database' => $this->file, 'prefix' => '', 'foreign_key_constraints' => true]]);

        return DB::connection('bankfile')->table($table);
    }
}
