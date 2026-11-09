<?php

namespace Tests\Feature\Sales;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Real contention: separate PHP processes, each with its own connection, issue invoices and record payments at the
 * same time. Numbers must be exactly 1..N, the journal must have no gap, no invoice may be over-allocated, one
 * idempotency key may record one payment, and the books must still verify. Meaningful on PostgreSQL (real row locks);
 * on SQLite it runs against a throwaway file.
 *
 * Does NOT use RefreshDatabase: other processes cannot see a transaction that is still open. It leaves its rows
 * behind on PostgreSQL (use a throwaway database) and removes its file on SQLite.
 */
class SalesConcurrencyTest extends TestCase
{
    /** @var array<string, string> */
    private array $env = [];

    /** @var array<string, mixed> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is not available.');
        }

        if (DB::getDriverName() === 'sqlite') {
            // SQLite lets one writer in and answers "database is locked" to a transaction that reads first and then tries to
            // write (what every posting does), so contention there tests SQLite, not the locks production relies on.
            // Production is PostgreSQL: run this class with phpunit.pgsql.xml.
            $this->markTestSkipped('Concurrency of postings is proved on PostgreSQL (phpunit.pgsql.xml), where the row locks are real.');
        }

        Artisan::call('migrate', ['--force' => true]);

        $this->ids = json_decode(trim($this->child(['php', 'tests/Support/sales_worker.php', 'setup'])), true, 512, JSON_THROW_ON_ERROR);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /** @param  list<string>  $command */
    private function child(array $command): string
    {
        $p = new Process($command, base_path(), $this->env + ['APP_ENV' => 'testing'], null, 300);
        $p->mustRun();

        return $p->getOutput();
    }

    /** @param  list<list<string>>  $commands @return list<string> the output of each, once all have finished */
    private function parallel(array $commands): array
    {
        $processes = array_map(fn (array $c) => tap(new Process($c, base_path(), $this->env + ['APP_ENV' => 'testing'], null, 300))->start(), $commands);
        $out = [];
        foreach ($processes as $p) {
            $p->wait();
            $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput().$p->getOutput());
            $out[] = $p->getOutput();
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function verify(): array
    {
        return json_decode(trim($this->child(['php', 'tests/Support/sales_worker.php', 'verify', (string) $this->ids['team']])), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_parallel_issues_get_consecutive_numbers_and_the_journal_has_no_gap(): void
    {
        $chunks = array_chunk($this->ids['drafts'], 6);
        $out = $this->parallel(array_map(fn ($chunk) => ['php', 'tests/Support/sales_worker.php', 'issue', (string) $this->ids['team'], (string) $this->ids['user'], implode(',', $chunk)], $chunks));

        $numbers = [];
        foreach ($out as $o) {
            $numbers = array_merge($numbers, array_filter(explode("\n", trim($o))));
        }
        sort($numbers);

        // INV-00001 went to the invoice issued during setup; the 24 drafts take 2..25
        $this->assertSame(array_map(fn ($n) => sprintf('INV-%05d', $n), range(2, 25)), $numbers, 'every number once, none skipped');

        $v = $this->verify();
        $this->assertTrue($v['entries_gap_free'], 'journal entry numbers have no gap');
        $this->assertTrue($v['verify_ok'], 'the books verify: '.json_encode($v['verify_failed']));
        $this->assertTrue($v['reconciles']);
        $this->assertCount(25, $v['invoice_numbers']);
    }

    public function test_parallel_payments_never_over_allocate_an_invoice(): void
    {
        // 4 workers x 5 payments of 100.00 against an invoice owing 1,150.00: eleven full payments fit, the rest are refused whole
        $out = $this->parallel(array_map(fn ($w) => ['php', 'tests/Support/sales_worker.php', 'pay', (string) $this->ids['team'], (string) $this->ids['user'], (string) $this->ids['bank'], (string) $this->ids['customer'], (string) $this->ids['invoice'], '5', '10000', 'w'.$w], range(1, 4)));

        $lines = collect($out)->flatMap(fn ($o) => array_filter(explode("\n", trim($o))));
        $this->assertSame(11, $lines->filter(fn ($l) => $l === 'ok')->count());
        $this->assertSame(9, $lines->filter(fn ($l) => str_starts_with($l, 'refused:'))->count());

        $v = $this->verify();
        $this->assertSame(11, $v['payments'], 'a refused payment leaves nothing behind');
        $this->assertTrue($v['verify_ok'], json_encode($v['verify_failed']));
        $this->assertTrue($v['reconciles']);
    }

    public function test_one_idempotency_key_used_by_parallel_workers_records_one_payment(): void
    {
        $this->parallel(array_map(fn () => ['php', 'tests/Support/sales_worker.php', 'pay', (string) $this->ids['team'], (string) $this->ids['user'], (string) $this->ids['bank'], (string) $this->ids['customer'], (string) $this->ids['invoice'], '3', '10000', 'same-key', 'shared'], range(1, 4)));

        $v = $this->verify();
        $this->assertSame(1, $v['payments']);
        $this->assertTrue($v['verify_ok'], json_encode($v['verify_failed']));
        $this->assertTrue($v['reconciles']);
    }
}
