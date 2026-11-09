<?php

namespace Tests\Feature\Ops;

use App\Models\Account;
use App\Services\CsvTransactionParser;
use App\Services\Purchases\ApLedger;
use App\Services\Purchases\MissingDocuments;
use App\Services\TransactionImporter;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsAccounting;
use Tests\Concerns\BuildsPurchases;
use Tests\TestCase;

/**
 * The cost of the things every page and every night's job does must not grow with the size of the ledger: a fixed number of
 * queries, and memory that does not follow the number of rows. Measured on a 200,000-row ledger in docs/R44_QA_perf.md;
 * these tests keep the shapes from coming back.
 */
class ScaleBoundsTest extends TestCase
{
    use BuildsAccounting;
    use BuildsPurchases;
    use RefreshDatabase;

    private function queries(callable $work): int
    {
        $n = 0;
        DB::listen(function () use (&$n) {
            $n++;
        });
        $work();

        return $n;
    }

    public function test_a_csv_is_read_row_by_row_and_a_large_file_does_not_cost_memory(): void
    {
        $path = sys_get_temp_dir().'/scale-bounds-'.uniqid().'.csv';
        $h = fopen($path, 'w');
        fwrite($h, "Date,Description,Amount\n");
        for ($i = 1; $i <= 40_000; $i++) {
            fwrite($h, sprintf("2026-03-%02d,Bank row number %d with a longer description,-%d.50\n", 1 + $i % 27, $i, $i));
        }
        fclose($h);

        $before = memory_get_usage();
        $peak = 0;
        $rows = 0;
        foreach (app(CsvTransactionParser::class)->stream($path)['rows'] as $row) {
            $rows++;
            $peak = max($peak, memory_get_usage() - $before);
        }
        @unlink($path);

        $this->assertSame(40_000, $rows);
        $this->assertLessThan(2 * 1024 * 1024, $peak, 'the parser holds a row, not the file (40,000 rows is about 2.5 MB of text)');
    }

    public function test_a_windows_1252_file_is_converted_as_it_streams(): void
    {
        $path = sys_get_temp_dir().'/scale-bounds-'.uniqid().'.csv';
        file_put_contents($path, "Date,Description,Amount\n2026-03-04,Caf\xE9 R\xE9sum\xE9,-12.00\n");
        $rows = iterator_to_array(app(CsvTransactionParser::class)->stream($path)['rows'], false);
        @unlink($path);

        $this->assertSame('Café Résumé', $rows[0]['description']);
    }

    public function test_a_file_over_the_page_limit_is_refused_on_the_page_and_allowed_from_the_command_line(): void
    {
        Storage::fake('local');
        config(['ops.import_max_rows' => 5]);
        $user = $this->user();
        $ctx = $this->personalContext($user);
        $account = Account::factory()->create(['user_id' => $user->id]);
        $csv = "Date,Description,Amount\n".implode("\n", array_map(fn ($i) => "2026-03-0{$i},Row {$i},-10.00", range(1, 7)));
        $importer = app(TransactionImporter::class);
        $token = $importer->stage($user, $ctx, $account, UploadedFile::fake()->createWithContent('big.csv', $csv));

        $page = $importer->analyse($user, $ctx, $token);
        $this->assertTrue($page['too_large']);
        $this->assertFalse($page['ready']);
        $this->assertSame(7, $page['counts']['total'], 'the whole file is still counted');

        $cli = app(TransactionImporter::class)->withoutRowLimit()->analyse($user, $ctx, $token);
        $this->assertFalse($cli['too_large']);
        $this->assertTrue($cli['ready']);
    }

    public function test_the_missing_documents_count_costs_the_same_queries_for_five_bills_and_for_fifty(): void
    {
        $this->setUpPurchases();
        $count = fn () => $this->queries(fn () => app(MissingDocuments::class)->countForLedger($this->ctx));

        for ($i = 0; $i < 5; $i++) {
            $this->postedBill(['supplier_reference' => 'S-'.$i, 'lines' => [$this->line(['unit_price_cents' => 100_000 + $i * 777])]]);
        }
        $few = $count();
        for ($i = 5; $i < 50; $i++) {
            $this->postedBill(['supplier_reference' => 'S-'.$i, 'lines' => [$this->line(['unit_price_cents' => 100_000 + $i * 777])]]);
        }
        $many = $count();

        $this->assertSame($few, $many);
        $this->assertSame(50, app(MissingDocuments::class)->countForLedger($this->ctx), 'and it counts what the list shows');
        $this->assertCount(50, app(MissingDocuments::class)->forLedger($this->ctx));
        $this->assertCount(6, app(MissingDocuments::class)->forLedger($this->ctx, 6), 'a limit builds only that many');
    }

    public function test_the_payables_open_items_cost_the_same_queries_for_three_unapplied_payments_and_for_thirty(): void
    {
        $this->setUpPurchases();
        $insert = function (int $from, int $to) {
            for ($i = $from; $i <= $to; $i++) {
                DB::table('payments')->insert($this->ctx->ownerAttributes() + [
                    'direction' => 'out', 'party_id' => $this->supplier->id, 'cash_account_id' => $this->bank->id, 'amount_cents' => 10_000 + $i, 'currency' => 'ZAR', 'base_amount_cents' => 10_000 + $i,
                    'paid_on' => '2026-11-01', 'method' => 'eft', 'reference' => 'ON-'.$i, 'idempotency_key' => 'scale-'.$i, 'status' => 'recorded', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        };
        $items = fn () => $this->queries(fn () => app(ApLedger::class)->openItems($this->ctx));

        $insert(1, 3);
        $few = $items();
        $insert(4, 30);
        $many = $items();

        $this->assertSame($few, $many, 'money paid on account is read in one query, not one per payment');
        $this->assertSame(30, app(ApLedger::class)->openItems($this->ctx)->where('type', 'on_account')->count());
        $this->assertTrue(app(ApLedger::class)->reconcile($this->ctx)['accounting_on']);
    }
}
