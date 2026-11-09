<?php

namespace Tests\Feature\Shared;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Real contention, not a simulation: separate PHP processes, each with its own database connection, take
 * invoice numbers in their own transactions at the same time, some of which fail after taking a number.
 * The committed numbers must be exactly 1..N: no duplicate, no gap.
 *
 * On SQLite this runs against a throwaway database file (SQLite lets one writer in at a time, which is the
 * contention). On PostgreSQL it runs against the test database and exercises the row lock itself, which is
 * the case that matters in production: run it there (phpunit.pgsql.xml).
 *
 * This class does NOT use RefreshDatabase: other processes cannot see a transaction that is still open.
 */
class NumberingConcurrencyTest extends TestCase
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
            $this->file = $dir.'/numbering-'.bin2hex(random_bytes(6)).'.sqlite';
            touch($this->file);
            $this->env = ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->file, 'DB_URL' => ''];
            $this->child(['php', 'artisan', 'migrate', '--force', '--no-interaction'], base_path());
        } else {
            Artisan::call('migrate', ['--force' => true]);
            $this->env = [];
        }

        $this->userId = (int) trim($this->child(['php', 'tests/Support/numbering_worker.php', 'setup'], base_path()));
    }

    protected function tearDown(): void
    {
        if ($this->file !== null) {
            @unlink($this->file);
        } elseif ($this->userId !== null) {
            DB::table('users')->where('id', $this->userId)->delete();   // sequences go with the user
        }

        parent::tearDown();
    }

    /** @param  list<string>  $command */
    private function child(array $command, string $cwd): string
    {
        $process = new Process($command, $cwd, $this->env + ['APP_ENV' => 'testing'], null, 120);
        $process->mustRun();

        return $process->getOutput();
    }

    /** @return list<Process> */
    private function startWorkers(int $workers, int $each, int $holdMs, int $abortEvery): array
    {
        $processes = [];
        for ($w = 0; $w < $workers; $w++) {
            $p = new Process(['php', 'tests/Support/numbering_worker.php', 'issue', (string) $this->userId, (string) $each, (string) $holdMs, (string) $abortEvery], base_path(), $this->env + ['APP_ENV' => 'testing'], null, 180);
            $p->start();
            $processes[] = $p;
        }

        return $processes;
    }

    public function test_parallel_processes_get_consecutive_numbers_with_no_duplicates_and_no_gaps(): void
    {
        $processes = $this->startWorkers(workers: 6, each: 8, holdMs: 15, abortEvery: 0);

        $numbers = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $numbers = array_merge($numbers, array_map('intval', array_filter(explode("\n", trim($process->getOutput())))));
        }

        sort($numbers);

        $this->assertCount(48, $numbers);
        $this->assertSame(range(1, 48), $numbers, 'exactly 1..48: every number once, none skipped');
        $this->assertSame('00049', trim($this->child(['php', 'tests/Support/numbering_worker.php', 'peek', (string) $this->userId], base_path())));
    }

    public function test_numbers_taken_by_failed_transactions_are_handed_out_again(): void
    {
        // every third transaction takes a number and then fails
        $processes = $this->startWorkers(workers: 4, each: 9, holdMs: 10, abortEvery: 3);

        $numbers = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $numbers = array_merge($numbers, array_map('intval', array_filter(explode("\n", trim($process->getOutput())))));
        }

        sort($numbers);

        $this->assertSame(4 * 6, count($numbers), '6 of each worker\'s 9 transactions commit');
        $this->assertSame(range(1, 24), $numbers, 'the failed ones left no hole');
        $this->assertSame('00025', trim($this->child(['php', 'tests/Support/numbering_worker.php', 'peek', (string) $this->userId], base_path())));
    }
}
