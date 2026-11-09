<?php

namespace Tests\Feature\Purchases;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Real contention: separate PHP processes with their own connections pay the same bill at the same moment.
 *
 *  - two DIFFERENT payments for the whole bill: exactly one lands, the other is refused (the document row is locked, the open balance
 *    re-read under the lock), so the bill can never be paid twice;
 *  - the SAME payment sent twice (a double click, a retried request): one payment exists and both callers get it.
 *
 * Runs against a throwaway SQLite file. On PostgreSQL the committed journal rows could not be removed afterwards (the journal tables are
 * append-only), so there it runs only when PURCHASES_PG_CONCURRENCY=1, against a throwaway database you drop afterwards.
 * Does NOT use RefreshDatabase: other processes cannot see an open transaction.
 */
class PurchasesConcurrencyTest extends TestCase
{
    private ?string $file = null;

    /** @var array<string, string> */
    private array $env = [];

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is not available.');
        }

        if (DB::getDriverName() === 'sqlite') {
            $dir = storage_path('framework/testing');
            @mkdir($dir, 0775, true);
            $this->file = $dir.'/purchases-'.bin2hex(random_bytes(6)).'.sqlite';
            touch($this->file);
            $this->env = ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->file, 'DB_URL' => ''];
            $this->child(['php', 'artisan', 'migrate', '--force', '--no-interaction']);
        } elseif (getenv('PURCHASES_PG_CONCURRENCY') === '1') {
            $this->artisan('migrate', ['--force' => true]);
        } else {
            $this->markTestSkipped('On PostgreSQL this leaves append-only journal rows behind: set PURCHASES_PG_CONCURRENCY=1 against a throwaway database.');
        }

        $this->ids = json_decode(trim($this->child(['php', 'tests/Support/purchases_worker.php', 'setup'])), true, flags: JSON_THROW_ON_ERROR);
    }

    protected function tearDown(): void
    {
        if ($this->file !== null) {
            @unlink($this->file);
        }

        parent::tearDown();
    }

    /** @param list<string> $command */
    private function child(array $command): string
    {
        $process = new Process($command, base_path(), $this->env + ['APP_ENV' => 'testing'], null, 180);
        $process->mustRun();

        return $process->getOutput();
    }

    /** @return list<string> the one-line answer of each worker */
    private function race(array $keys, int $amount): array
    {
        $processes = [];
        foreach ($keys as $key) {
            $p = new Process(['php', 'tests/Support/purchases_worker.php', 'pay', (string) $this->ids['team'], (string) $this->ids['user'], (string) $this->ids['account'], (string) $this->ids['party'], (string) $this->ids['bill'], $key, (string) $amount, '0'], base_path(), $this->env + ['APP_ENV' => 'testing'], null, 180);
            $p->start();
            $processes[] = $p;
        }

        return array_map(function (Process $p) {
            $p->wait();

            return trim($p->getOutput());
        }, $processes);
    }

    /** A win, a refusal with a reason, or (SQLite only, which has one writer and says so) "database is locked" with nothing written. */
    private function isCleanRefusalOrWin(string $answer): bool
    {
        return str_starts_with($answer, 'ok ') || str_starts_with($answer, 'refused ') || ($this->file !== null && str_contains($answer, 'database is locked'));
    }

    private function paymentCount(): int
    {
        return (int) trim($this->child(['php', '-r', 'require "vendor/autoload.php"; $a = require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo Illuminate\Support\Facades\DB::table("payments")->where("team_id", '.$this->ids['team'].')->where("status", "recorded")->count();']));
    }

    public function test_two_different_payments_for_the_whole_bill_cannot_both_land(): void
    {
        $answers = $this->race(['race-a', 'race-b', 'race-c'], 115_000);

        $ok = array_values(array_filter($answers, fn ($a) => str_starts_with($a, 'ok ')));
        $this->assertCount(1, $ok, 'exactly one payment lands: '.json_encode($answers));
        $this->assertSame(1, $this->paymentCount());
        foreach ($answers as $answer) {
            $this->assertTrue($this->isCleanRefusalOrWin($answer), 'a refusal is clean: '.$answer);
        }
    }

    public function test_the_same_payment_sent_twice_at_once_is_one_payment(): void
    {
        $answers = $this->race(['double-click', 'double-click'], 115_000);

        $this->assertSame(1, $this->paymentCount(), json_encode($answers));
        $wins = array_values(array_unique(array_filter($answers, fn ($a) => str_starts_with($a, 'ok '))));
        $this->assertCount(1, $wins, 'whoever got an answer was given the same payment: '.json_encode($answers));
        foreach ($answers as $answer) {
            $this->assertTrue($this->isCleanRefusalOrWin($answer), $answer);
        }
        if ($this->file === null) {
            $this->assertSame($answers[0], $answers[1], 'on PostgreSQL the second caller waits and is given the first one\'s payment');
        }
    }
}
