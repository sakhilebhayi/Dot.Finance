<?php

namespace Tests\Feature\Ops;

use App\Services\Sales\ReceivablesLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsSales;
use Tests\TestCase;

/** Receivables are summed in the database, not by loading the open invoices: see docs/R44_QA_perf.md. */
class ScaleBoundsSalesTest extends TestCase
{
    use BuildsSales;
    use RefreshDatabase;

    public function test_receivables_are_worked_out_in_the_database_and_agree_with_the_books(): void
    {
        $this->setUpSales();
        $ledger = app(ReceivablesLedger::class);
        $one = $this->issued([$this->line('A', '1', 100_000)]);
        $two = $this->issued([$this->line('B', '2', 50_000)]);

        $rows = $this->queries(fn () => $ledger->summary($this->ledger));
        $this->assertLessThan(20, $rows);

        $summary = $ledger->summary($this->ledger);
        $this->assertSame(2, $summary['open_count']);
        $this->assertSame((int) $one->total_cents + (int) $two->total_cents, $summary['owed_base_cents']);
        $this->assertTrue($summary['reconciles']);
        $this->assertCount(1, $ledger->openItems($this->ledger, null, 1), 'a limit loads only that many documents');
        $this->assertSame(2, $ledger->openCount($this->ledger));
        $this->assertTrue($ledger->reconcile($this->ledger)['ok']);
    }

    private function queries(callable $work): int
    {
        $n = 0;
        DB::listen(function () use (&$n) {
            $n++;
        });
        $work();

        return $n;
    }
}
