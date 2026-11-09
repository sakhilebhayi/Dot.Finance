<?php

namespace Tests\Feature\Bank;

use App\Models\Account;
use App\Models\Bank\BankStatement;
use App\Models\Bank\BankStatementLine;
use App\Models\User;
use App\Services\Bank\StatementImporter;
use App\Support\Ledger\LedgerContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsBankFixtures;
use Tests\TestCase;

class StatementImportTest extends TestCase
{
    use BuildsBankFixtures;
    use RefreshDatabase;

    private User $user;

    private Account $account;

    private LedgerContext $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->user = $this->user();
        $this->ctx = LedgerContext::personal($this->user);
        $this->account = Account::factory()->create(['user_id' => $this->user->id, 'name' => 'Cheque', 'opening_balance_cents' => 0, 'opening_balance_date' => '2026-01-01']);
    }

    private function fixture(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, file_get_contents(base_path('tests/Fixtures/bank/'.$name)));
    }

    private function importer(): StatementImporter
    {
        return app(StatementImporter::class);
    }

    /** @return array<string, mixed> */
    private function import(UploadedFile $file, array $options = [], ?Account $account = null): array
    {
        return LedgerContext::run($this->ctx, function () use ($file, $options, $account) {
            $token = $this->importer()->stage($this->user, $this->ctx, $account ?? $this->account, $file);

            return $this->importer()->commit($this->user, $this->ctx, $token, $options);
        });
    }

    private function preview(UploadedFile $file, array $options = []): array
    {
        return LedgerContext::run($this->ctx, function () use ($file, $options) {
            $token = $this->importer()->stage($this->user, $this->ctx, $this->account, $file);

            return $this->importer()->analyse($this->user, $this->ctx, $token, $options);
        });
    }

    public function test_an_sgml_ofx_file_is_read_with_its_balance_period_and_bank_ids(): void
    {
        $result = $this->import($this->fixture('statement-sgml.ofx'));

        $this->assertSame(3, $result['imported']);
        $statement = $result['statement'];
        $this->assertSame('ofx', $statement->source);
        $this->assertSame('2026-10-03', $statement->date_from->toDateString());
        $this->assertSame('2026-10-31', $statement->date_to->toDateString());
        $this->assertSame(12_043_050, $statement->closing_balance_cents);
        $this->assertSame(2_500_000 - 450_050 - 6_900, $statement->in_cents + $statement->out_cents);

        $line = BankStatementLine::query()->forContext($this->ctx)->orderBy('line_no')->first();
        $this->assertSame(2_500_000, $line->amount_cents);
        $this->assertSame('20261003-0001', $line->external_id);
        $this->assertSame('EFT CREDIT ACME LTD INV-0042', $line->description);
    }

    public function test_an_xml_ofx_file_with_entities_and_a_comma_amount(): void
    {
        $result = $this->import($this->fixture('statement-xml.ofx'));

        $this->assertSame(2, $result['imported']);
        $lines = BankStatementLine::query()->forContext($this->ctx)->orderBy('line_no')->get();
        $this->assertSame(-125_000, $lines[0]->amount_cents);
        $this->assertSame('Telkom & Co Fibre October', $lines[0]->description);
        $this->assertSame(1_055_000, $result['statement']->closing_balance_cents);
    }

    public function test_a_qif_file_needs_the_date_order_and_number_format_to_be_stated(): void
    {
        $ambiguous = UploadedFile::fake()->createWithContent('a.qif', "!Type:Bank\nD03/10/2026\nT10.00\nPX\n^\nD06/10/2026\nT5.00\nPY\n^\n");
        $this->assertFalse($this->preview($ambiguous)['ready'], 'a day/month ambiguous QIF is not imported on a guess');
        $this->assertTrue($this->preview(UploadedFile::fake()->createWithContent('a.qif', "!Type:Bank\nD03/10/2026\nT10.00\nPX\n^\n"), ['date_format' => 'mdy', 'amount_locale' => 'en'])['ready']);

        $result = $this->import($this->fixture('statement.qif'));
        $this->assertSame(3, $result['imported']);
        $this->assertSame('2026-10-03', BankStatementLine::query()->forContext($this->ctx)->orderBy('line_no')->first()->posted_on->toDateString());
    }

    public function test_a_csv_with_a_descending_running_balance_proves_its_own_opening_and_closing_balance(): void
    {
        $preview = $this->preview($this->fixture('running-balance-descending.csv'));

        $this->assertSame(10_000_000, $preview['balances']['opening']);
        $this->assertSame(12_043_050, $preview['balances']['closing']);
        $this->assertTrue($preview['balances']['foot']);
        $this->assertSame('running balance', $preview['balances']['closing_source']);
    }

    public function test_debit_and_credit_columns_with_comma_decimals_and_a_chosen_mapping(): void
    {
        $options = ['columns' => ['date' => 0, 'description' => 1, 'debit' => 2, 'credit' => 3], 'has_header' => true, 'date_format' => 'dmy', 'amount_locale' => 'eu'];
        $result = $this->import($this->fixture('debit-credit-semicolon.csv'), $options);

        $this->assertSame(3, $result['imported']);
        $amounts = BankStatementLine::query()->forContext($this->ctx)->orderBy('line_no')->pluck('amount_cents')->all();
        $this->assertSame([2_500_000, -450_050, -100_000], $amounts);
    }

    public function test_an_unreadable_row_is_listed_and_skipped_not_guessed(): void
    {
        $csv = "Date,Description,Amount\n2026-10-01,Good row,10.00\nnot a date,Bad date,5.00\n2026-10-02,Bad amount,abc\n2026-10-03,Zero,0.00\n";
        $preview = $this->preview(UploadedFile::fake()->createWithContent('odd.csv', $csv));

        $this->assertSame(4, $preview['counts']['total']);
        $this->assertSame(3, $preview['counts']['invalid']);
        $this->assertSame(1, $preview['counts']['importable']);
    }

    public function test_the_same_file_twice_changes_nothing(): void
    {
        $first = $this->import($this->fixture('statement-sgml.ofx'));
        $second = $this->import($this->fixture('statement-sgml.ofx'));

        $this->assertFalse($first['already_imported']);
        $this->assertTrue($second['already_imported']);
        $this->assertSame($first['statement']->id, $second['statement']->id);
        $this->assertSame(3, BankStatementLine::query()->forContext($this->ctx)->count());
        $this->assertSame(1, BankStatement::query()->forContext($this->ctx)->count());
    }

    public function test_an_overlapping_statement_adds_only_the_lines_it_does_not_hold(): void
    {
        $this->import(UploadedFile::fake()->createWithContent('a.csv', "Date,Description,Amount\n2026-10-01,Rent,-5000.00\n2026-10-02,Coffee,-45.00\n2026-10-02,Coffee,-45.00\n"));
        $result = $this->import(UploadedFile::fake()->createWithContent('b.csv', "Date,Description,Amount\n2026-10-02,Coffee,-45.00\n2026-10-02,Coffee,-45.00\n2026-10-03,Salary,30000.00\n"));

        $this->assertSame(1, $result['imported']);
        $this->assertSame(2, $result['duplicates'], 'both identical coffees are recognised, not collapsed into one');
        $this->assertSame(4, BankStatementLine::query()->forContext($this->ctx)->count());
    }

    public function test_two_real_identical_lines_in_one_file_are_two_lines(): void
    {
        $result = $this->import(UploadedFile::fake()->createWithContent('a.csv', "Date,Description,Amount\n2026-10-02,Coffee,-45.00\n2026-10-02,Coffee,-45.00\n"));

        $this->assertSame(2, $result['imported']);
    }

    public function test_a_file_in_another_currency_is_refused(): void
    {
        $usd = Account::factory()->currency('USD')->create(['user_id' => $this->user->id]);
        $ofx = str_replace('<CURDEF>ZAR', '<CURDEF>ZAR', file_get_contents(base_path('tests/Fixtures/bank/statement-sgml.ofx')));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('in ZAR but the account holds USD');
        $this->import(UploadedFile::fake()->createWithContent('x.ofx', $ofx), [], $usd);
    }

    public function test_a_staged_file_belongs_to_the_ledger_and_person_that_staged_it(): void
    {
        $token = LedgerContext::run($this->ctx, fn () => $this->importer()->stage($this->user, $this->ctx, $this->account, $this->fixture('statement-sgml.ofx')));

        $other = $this->user();
        $this->assertNull($this->importer()->load($other, LedgerContext::personal($other), $token));
        $this->assertNull($this->importer()->load($this->user, LedgerContext::company($this->company($this->user)), $token));
        $this->assertNotNull($this->importer()->load($this->user, $this->ctx, $token));
    }

    public function test_an_unused_statement_can_be_discarded_but_a_worked_one_is_kept(): void
    {
        $result = $this->import($this->fixture('statement-sgml.ofx'));

        LedgerContext::run($this->ctx, function () use ($result) {
            $this->assertSame(3, $this->importer()->discardUnused($result['statement']));
        });
        $this->assertSame(0, BankStatement::query()->forContext($this->ctx)->count());

        $again = $this->import($this->fixture('statement-sgml.ofx'));
        $this->openSession($this->user, $this->account, '2026-10-31', 1, 0);
        // a reconciliation started from the statement keeps it
        LedgerContext::run($this->ctx, function () use ($again) {
            DB::table('bank_reconciliations')->update(['bank_statement_id' => $again['statement']->id]);
            $this->expectException(\InvalidArgumentException::class);
            $this->importer()->discardUnused($again['statement']);
        });
    }

    public function test_a_statement_line_is_immutable_and_cannot_be_deleted_once_matched(): void
    {
        $this->import($this->fixture('statement-sgml.ofx'));
        $line = BankStatementLine::query()->forContext($this->ctx)->first();

        try {
            $line->update(['amount_cents' => 1]);
            $this->fail('A statement line is what the bank said');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('what the bank said', $e->getMessage());
        }

        $this->expectException(QueryException::class);
        DB::table('bank_statement_lines')->where('id', $line->id)->update(['amount_cents' => 2]);
    }

    public function test_five_thousand_lines_import_in_a_bounded_number_of_queries_and_reimport_is_idempotent(): void
    {
        $rows = ['Date,Description,Amount'];
        for ($i = 0; $i < 5000; $i++) {
            $rows[] = sprintf('2025-%02d-%02d,Payment %d,-%d.%02d', ($i % 12) + 1, ($i % 28) + 1, $i, 10 + $i % 900, $i % 100);
        }
        $csv = implode("\n", $rows)."\n";

        DB::enableQueryLog();
        $result = $this->import(UploadedFile::fake()->createWithContent('big.csv', $csv));
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(5000, $result['imported']);
        $this->assertLessThan(60, $queries, "importing 5,000 lines took {$queries} queries; it must be chunked, not per line");

        // the same lines in a differently-named file: nothing new
        $again = $this->import(UploadedFile::fake()->createWithContent('big-again.csv', $csv."\n"));
        $this->assertTrue($again['already_imported'] || $again['imported'] === 0);

        $reordered = implode("\n", array_merge([$rows[0]], array_reverse(array_slice($rows, 1))))."\n";
        $third = $this->import(UploadedFile::fake()->createWithContent('big-reversed.csv', $reordered));
        $this->assertSame(0, $third['imported']);
        $this->assertSame(5000, $third['duplicates']);
        $this->assertSame(5000, BankStatementLine::query()->forContext($this->ctx)->count());
    }
}
