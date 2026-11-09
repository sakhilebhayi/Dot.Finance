<?php

namespace Tests\Feature\Ops;

use App\Exceptions\ImportInterrupted;
use App\Jobs\Reporting\GenerateReportExport;
use App\Models\Account;
use App\Models\Accounting\JournalEntry;
use App\Models\Intelligence\FinanceInsight;
use App\Models\Reporting\ReportExport;
use App\Models\Sales\SalesDocument;
use App\Models\Sales\SalesRecurringTemplate;
use App\Models\Sales\SalesReminder;
use App\Models\Shared\Party;
use App\Models\Tax\TaxPayrollLine;
use App\Models\Tax\TaxPayrollRun;
use App\Models\Transaction;
use App\Services\Accounting\GlVerifier;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\PostingLine;
use App\Services\Accounting\PostingRequest;
use App\Services\Intelligence\Detectors\Detector;
use App\Services\Intelligence\Detectors\Finding;
use App\Services\Intelligence\InsightEngine;
use App\Services\Reporting\ExportService;
use App\Services\Sales\DocumentIssuer;
use App\Services\Sales\RecurringInvoices;
use App\Services\Sales\Reminders;
use App\Services\Tax\PayrollImport;
use App\Services\TransactionImporter;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\BuildsAccounting;
use Tests\Concerns\BuildsReportingLedger;
use Tests\Concerns\BuildsSales;
use Tests\TestCase;

/**
 * What a job that dies half way leaves behind. Each test makes the job fail (or abandons it, as a killed worker would) in the
 * middle of its work, then runs it again, and checks that the second run finishes the work without duplicating a record that
 * the first run had already written, and that nothing half-written was left. The real kill -9 drills on PostgreSQL are in
 * docs/R44_QA_perf.md; these are the repeatable tests behind them.
 */
class JobRecoveryTest extends TestCase
{
    use BuildsAccounting;
    use BuildsReportingLedger;
    use BuildsSales;
    use RefreshDatabase;

    // ---- posting ---------------------------------------------------------------------------------------------------

    public function test_a_posting_that_fails_while_its_lines_are_written_leaves_no_entry_and_no_gap_in_the_numbers(): void
    {
        $user = $this->user();
        $ctx = $this->personalContext($user);
        $this->enableAccounting($ctx);
        $poster = app(JournalPoster::class);

        $good = fn (string $id) => new PostingRequest(CarbonImmutable::parse('2026-03-05'), 'manual', $id, 1, 'Recovery '.$id, [new PostingLine('bank', debitCents: 1000), new PostingLine('sales', creditCents: 1000)]);

        LedgerContext::run($ctx, function () use ($poster, $good, $ctx) {
            $poster->post($good('one'));

            // the worker dies right after the lines are written, before the commit: the entry and its lines are already in the open transaction
            $die = true;
            DB::listen(function ($query) use (&$die) {
                if ($die && str_contains($query->sql, 'insert into "journal_lines"')) {
                    throw new RuntimeException('the worker died');
                }
            });
            try {
                $poster->post($good('two'));
                $this->fail('the posting should have died');
            } catch (RuntimeException) {
                // expected
            }
            $die = false;

            $this->assertSame(0, JournalEntry::query()->forContext($ctx)->where('source_id', 'two')->count(), 'no entry without its lines');
            $this->assertSame(1, JournalEntry::query()->forContext($ctx)->count());

            $next = $poster->post($good('three'));
            $this->assertSame(2, (int) $next->entry_number, 'the number the failed posting took is used by the next one: no gap');
            $this->assertTrue(app(GlVerifier::class)->verify($ctx)['ok'], 'the books still verify');
        });
    }

    // ---- report exports --------------------------------------------------------------------------------------------

    public function test_an_export_whose_worker_was_killed_is_taken_over_once_its_claim_is_stale_and_makes_one_file(): void
    {
        Storage::fake('local');
        $this->buildCompanyBooks();
        $service = app(ExportService::class);
        Queue::fake();
        $params = ['from' => '2026-01-01', 'to' => '2026-06-30'];
        $export = $service->request($this->ctx, $this->owner, 'profit-loss', 'csv', $params);

        // the worker claimed it and was killed: the row says "running" and nothing will ever finish it
        $export->forceFill(['status' => 'running', 'started_at' => now()->subMinutes(2), 'attempts' => 1])->save();
        Queue::assertPushed(GenerateReportExport::class, 1);

        // a fresh claim belongs to a live worker: asking again changes nothing and a second job leaves it alone
        $service->request($this->ctx, $this->owner, 'profit-loss', 'csv', $params);
        Queue::assertPushed(GenerateReportExport::class, 1);
        $this->assertSame('running', $service->generate($export->refresh())->status);

        // the claim goes stale: asking again puts it back in the queue ...
        $export->forceFill(['started_at' => now()->subMinutes(ExportService::STALE_CLAIM_MINUTES + 1)])->save();
        $service->request($this->ctx, $this->owner, 'profit-loss', 'csv', $params);
        Queue::assertPushed(GenerateReportExport::class, 2);
        $this->assertSame('queued', $export->refresh()->status);

        // ... and the job makes exactly one file and one row, however many times it is delivered
        $job = new GenerateReportExport($export->id, null, $this->company->id);
        $job->handle($service);
        $job->handle($service);
        $this->assertSame(1, ReportExport::query()->forContext($this->ctx)->count());
        $row = $export->refresh();
        $this->assertSame('done', $row->status);
        Storage::disk('local')->assertExists($row->path);
        $this->assertCount(1, Storage::disk('local')->allFiles('report-exports'));
    }

    public function test_the_daily_prune_requeues_exports_whose_worker_died(): void
    {
        Storage::fake('local');
        $this->buildCompanyBooks();
        Queue::fake();
        $export = app(ExportService::class)->request($this->ctx, $this->owner, 'profit-loss', 'csv', ['from' => '2026-01-01', 'to' => '2026-06-30']);
        $export->forceFill(['status' => 'running', 'started_at' => now()->subHour(), 'attempts' => 1])->save();

        $this->artisan('finance:reports:prune-exports')->expectsOutputToContain('whose worker died')->assertExitCode(0);

        $this->assertSame('queued', $export->refresh()->status);
        Queue::assertPushed(GenerateReportExport::class, 2);
    }

    // ---- CSV import ------------------------------------------------------------------------------------------------

    public function test_an_import_that_stops_between_chunks_keeps_the_whole_chunks_and_a_second_run_imports_only_the_rest(): void
    {
        Storage::fake('local');
        $user = $this->user();
        $ctx = $this->personalContext($user);
        $account = Account::factory()->create(['user_id' => $user->id, 'opening_balance_cents' => 0, 'opening_balance_date' => '2025-01-01']);
        $lines = ['Date,Description,Amount'];
        for ($i = 1; $i <= 1100; $i++) {
            $lines[] = sprintf('2026-03-%02d,Recovery row %d,-%d.00', 1 + $i % 27, $i, 10 + $i);
        }
        $importer = app(TransactionImporter::class);

        $run = function () use ($importer, $user, $ctx, $account, $lines) {
            $token = $importer->stage($user, $ctx, $account, UploadedFile::fake()->createWithContent('bank.csv', implode("\n", $lines)));

            return LedgerContext::run($ctx, fn () => $importer->commit($user, $ctx, $token, 'ymd', 'en', null, []));
        };

        // the 700th row dies: rows 501-1000 are the second chunk and roll back as a whole; rows 1-500 stay
        $seen = 0;
        Event::listen('eloquent.creating: '.Transaction::class, function () use (&$seen) {
            if (++$seen === 700) {
                throw new RuntimeException('the worker died');
            }
        });
        try {
            $run();
            $this->fail('the import should have been interrupted');
        } catch (ImportInterrupted $e) {
            $this->assertSame(500, $e->imported);
        }
        $this->assertSame(500, Transaction::query()->forContext($ctx)->count(), 'whole chunks only: the half-written second chunk is gone');

        Event::forget('eloquent.creating: '.Transaction::class);
        $result = $run();

        $this->assertSame(500, $result['skipped_duplicates'], 'the rows already imported are recognised and skipped');
        $this->assertSame(600, $result['imported']);
        $this->assertSame(1100, Transaction::query()->forContext($ctx)->count());
        $this->assertSame(1100, Transaction::query()->forContext($ctx)->distinct()->count('import_fingerprint'), 'no row twice');
    }

    // ---- repeating invoices ----------------------------------------------------------------------------------------

    public function test_repeating_invoices_that_stop_part_way_finish_on_the_next_run_with_one_invoice_per_template_and_period(): void
    {
        $this->setUpSales();
        $today = CarbonImmutable::today();
        $template = fn (Party $party, string $name) => SalesRecurringTemplate::create($this->ledger->ownerAttributes() + [
            'party_id' => $party->id, 'name' => $name, 'frequency' => 'monthly', 'every' => 1, 'start_date' => $today->subMonth()->toDateString(),
            'next_run_date' => $today->subMonth()->toDateString(), 'mode' => 'draft', 'currency' => 'ZAR', 'terms_days' => 14, 'status' => 'active', 'created_by' => $this->owner->id,
            'lines' => [['description' => $name, 'quantity' => '1', 'unit_cents' => 500_000, 'tax_code' => 'ZA_VAT_STD']],
        ]);
        $other = LedgerContext::run($this->ledger, fn () => Party::create($this->ledger->ownerAttributes() + ['type' => 'customer', 'name' => 'Second Co', 'status' => 'active']));
        $third = LedgerContext::run($this->ledger, fn () => Party::create($this->ledger->ownerAttributes() + ['type' => 'customer', 'name' => 'Third Co', 'status' => 'active']));
        $t1 = $template($this->customer, 'First');
        $t2 = $template($other, 'Second');
        $t3 = $template($third, 'Third');

        // the second template's invoice cannot be written: the run stops there
        Event::listen('eloquent.creating: '.SalesDocument::class, function (SalesDocument $d) use ($other) {
            if ($d->party_id === $other->id) {
                throw new RuntimeException('the worker died');
            }
        });
        try {
            LedgerContext::run($this->ledger, fn () => app(RecurringInvoices::class)->runFor($this->ledger, $today));
            $this->fail('the run should have stopped');
        } catch (RuntimeException) {
            // expected
        }
        $count = fn (SalesRecurringTemplate $t) => SalesDocument::query()->forContext($this->ledger)->where('recurring_template_id', $t->id)->count();
        $this->assertSame(2, $count($t1), 'the first template finished both of its periods before the failure');
        $this->assertSame(0, $count($t2));
        $this->assertSame(0, $count($t3));

        Event::forget('eloquent.creating: '.SalesDocument::class);
        LedgerContext::run($this->ledger, fn () => app(RecurringInvoices::class)->runFor($this->ledger, $today));

        foreach ([$t1, $t2, $t3] as $t) {
            $this->assertSame(2, $count($t), 'last month and this month, once each');
            $this->assertSame(2, SalesDocument::query()->forContext($this->ledger)->where('recurring_template_id', $t->id)->distinct()->count('recurring_period'));
        }
    }

    // ---- reminders -------------------------------------------------------------------------------------------------

    public function test_a_reminder_is_sent_at_most_once_even_when_the_run_died_after_logging_it(): void
    {
        $this->setUpSales();
        Mail::fake();
        $today = CarbonImmutable::today();
        $draft = $this->draft([$this->line('Work', '1', 100_000)], ['issue_date' => $today->subDays(30)->toDateString(), 'due_date' => $today->toDateString()]);
        $document = LedgerContext::run($this->ledger, fn () => app(DocumentIssuer::class)->issue($draft, $this->owner)['document']);

        // a worker wrote the log row ("pending") and died before it handed the message over
        SalesReminder::query()->create($this->ledger->ownerAttributes() + [
            'document_id' => $document->id, 'party_id' => $document->party_id, 'stage' => 'on_due', 'offset_days' => 0, 'scheduled_for' => $today->toDateString(),
            'outcome' => 'pending', 'to_address' => 'ap@acme.test',
        ]);

        $counts = LedgerContext::run($this->ledger, fn () => app(Reminders::class)->runFor($this->ledger, $today));

        $this->assertSame(1, $counts['already_logged']);
        $this->assertSame(0, $counts['handed_to_mailer'], 'a reminder is never sent twice: the unsent one waits for a person (the history shows it as pending)');
        Mail::assertNothingSent();
        $this->assertSame(1, SalesReminder::query()->forContext($this->ledger)->count());
    }

    // ---- findings --------------------------------------------------------------------------------------------------

    public function test_one_detector_failing_does_not_stop_the_others_and_the_next_scan_creates_nothing_twice(): void
    {
        $user = $this->user();
        $ctx = $this->personalContext($user);
        $this->enableAccounting($ctx);
        $broken = new class implements Detector
        {
            public function kind(): string
            {
                return 'broken';
            }

            public function period(CarbonImmutable $asOf): string
            {
                return $asOf->format('Y-m');
            }

            public function detect(LedgerContext $context, CarbonImmutable $asOf): array
            {
                throw new RuntimeException('the detector died');
            }
        };
        $one = new class implements Detector
        {
            public function kind(): string
            {
                return 'recovery_one';
            }

            public function period(CarbonImmutable $asOf): string
            {
                return $asOf->format('Y-m');
            }

            public function detect(LedgerContext $context, CarbonImmutable $asOf): array
            {
                return [new Finding('recovery_one', 'subject-1', $asOf->format('Y-m'), 'notice', 'Something to look at', 'Body.', [], null)];
            }
        };
        $asOf = CarbonImmutable::parse('2026-06-15');

        $first = (new InsightEngine([$broken, $one]))->scan($ctx, $asOf);
        $second = (new InsightEngine([$one, $broken]))->scan($ctx, $asOf);

        $this->assertCount(1, $first['created'], 'the working detector still ran');
        $this->assertCount(0, $second['created']);
        $this->assertSame(1, FinanceInsight::query()->forContext($ctx)->count());
    }

    // ---- payroll import --------------------------------------------------------------------------------------------

    public function test_a_payroll_import_that_dies_while_its_lines_are_written_leaves_no_run_and_the_retry_creates_one(): void
    {
        $this->seedZa();
        $owner = $this->user();
        $company = $this->company($owner, 'Payroll Recovery (Pty) Ltd');
        $ctx = LedgerContext::company($company);
        $this->enableAccounting($ctx);
        config(['tax.payroll_import.secret' => 'test-secret']);
        $payload = [
            'schema' => PayrollImport::SCHEMA, 'hr_run_id' => 'HR-2026-10', 'company_uuid' => $company->infodot_team_uuid, 'period' => '2026-10', 'currency' => 'ZAR',
            'issued_at' => now()->toIso8601String(),
            'totals' => ['gross_pay_cents' => 3_000_000, 'paye_cents' => 400_000, 'uif_employee_cents' => 30_000, 'uif_employer_cents' => 30_000, 'sdl_cents' => 30_000, 'eti_claimed_cents' => 0, 'employee_count' => 2],
            'employees' => [
                ['ref' => 'E-1', 'name' => 'Thandi Test', 'gross_cents' => 2_000_000, 'paye_cents' => 300_000, 'uif_employee_cents' => 20_000, 'uif_employer_cents' => 20_000, 'sdl_cents' => 20_000],
                ['ref' => 'E-2', 'name' => 'Sipho Test', 'gross_cents' => 1_000_000, 'paye_cents' => 100_000, 'uif_employee_cents' => 10_000, 'uif_employer_cents' => 10_000, 'sdl_cents' => 10_000],
            ],
        ];
        $seen = 0;
        Event::listen('eloquent.creating: '.TaxPayrollLine::class, function () use (&$seen) {
            if (++$seen === 2) {
                throw new RuntimeException('the worker died');
            }
        });
        try {
            app(PayrollImport::class)->import($payload);
            $this->fail('the import should have died');
        } catch (RuntimeException) {
            // expected
        }
        $this->assertSame(0, TaxPayrollRun::query()->forContext($ctx)->count(), 'the run row is rolled back with the lines');
        $this->assertSame(0, DB::table('tax_payroll_lines')->count());

        Event::forget('eloquent.creating: '.TaxPayrollLine::class);
        $result = app(PayrollImport::class)->import($payload);
        $again = app(PayrollImport::class)->import($payload);

        $this->assertSame('created', $result['status']);
        $this->assertSame('unchanged', $again['status']);
        $this->assertSame(1, TaxPayrollRun::query()->forContext($ctx)->count());
        $this->assertSame(2, DB::table('tax_payroll_lines')->count());
    }
}
