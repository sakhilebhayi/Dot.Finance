<?php

namespace Tests\Feature\Acceptance;

use App\Jobs\Reporting\GenerateReportExport;
use App\Mail\SalesReminderMail;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalLine;
use App\Models\Purchases\Bill;
use App\Models\Purchases\RecurringBill;
use App\Models\Reporting\ReportExport;
use App\Models\Sales\SalesDocument;
use App\Models\Sales\SalesRecurringTemplate;
use App\Models\Sales\SalesReminder;
use App\Models\Shared\Payment;
use App\Models\Transaction;
use App\Services\Purchases\RecurringBills;
use App\Services\Reporting\ExportService;
use App\Services\Sales\RecurringInvoices;
use App\Services\Sales\Reminders;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Owner criterion 33: failed jobs recover without duplicating financial records. A fault is injected part-way through each flow
 * (the second journal line of an invoice, the cash row of a payment, the second invoice of a repeating run, the file write of an
 * export, the mail server, the sweeper's database) by listening to the model events and throwing once; then the same request is made
 * again, the way a retry, a queue worker or a person would. Each time: the failed attempt left nothing behind (no number used, no
 * half posting, no payment, no document), and the retry produced exactly one of everything.
 */
class Acceptance33_FailedJobsRecoverTest extends AcceptanceCase
{
    private bool $armed = false;

    private int $seen = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpIntegration();
    }

    /** Throw RuntimeException('injected fault') on the $nth time $model fires $event while armed. */
    private function injectFault(string $model, string $event, int $nth = 1, ?callable $when = null): void
    {
        $this->armed = true;
        $this->seen = 0;
        Event::listen("eloquent.{$event}: {$model}", function ($row) use ($nth, $when) {
            if ($this->armed && ($when === null || $when($row)) && ++$this->seen === $nth) {
                throw new \RuntimeException('injected fault');
            }
        });
    }

    /** Throw right AFTER the $nth statement containing $needle has run, so that its rows are written and what should follow is not. */
    private function injectSqlFault(string $needle, int $nth = 1): void
    {
        $this->armed = true;
        $this->seen = 0;
        DB::listen(function ($query) use ($needle, $nth) {
            if ($this->armed && str_contains($query->sql, $needle) && ++$this->seen === $nth) {
                throw new \RuntimeException('injected fault');
            }
        });
    }

    private function recover(): void
    {
        $this->armed = false;
    }

    /** @return array<string, int> */
    private function books(): array
    {
        return [
            'journals' => JournalEntry::query()->forContext($this->ledger)->count(), 'lines' => JournalLine::query()->forContext($this->ledger)->count(),
            'payments' => Payment::query()->forContext($this->ledger)->count(), 'transactions' => Transaction::query()->forContext($this->ledger)->count(),
            'invoices' => SalesDocument::query()->forContext($this->ledger)->count(), 'bills' => Bill::query()->forContext($this->ledger)->count(),
        ];
    }

    public function test_an_invoice_whose_posting_fails_half_way_leaves_no_number_no_entry_and_issues_cleanly_on_the_retry(): void
    {
        $this->asOwner()->post(route('sales.documents.store'), ['kind' => 'tax_invoice', 'party_id' => $this->acme->id, 'currency' => 'ZAR', 'issue_date' => '2026-11-05', 'client_token' => 'f1',
            'lines' => [['description' => 'Job', 'quantity' => '1', 'unit_price' => '1000.00', 'tax_code' => 'ZA_VAT_STD']]])->assertSessionHasNoErrors();
        $doc = SalesDocument::query()->forContext($this->ledger)->firstOrFail();
        $before = $this->books();

        $this->injectSqlFault('insert into "journal_lines"');                          // the entry and its lines are written; the document is not yet marked issued
        $this->asOwner()->post(route('sales.documents.issue', $doc));
        $this->assertSame($before, $this->books(), 'nothing of the half posting survived');
        $doc->refresh();
        $this->assertSame('draft', $doc->status);
        $this->assertNull($doc->number, 'the number was handed back with the rollback');
        $this->assertNull($doc->sealed_at);

        $this->recover();
        $this->asOwner()->post(route('sales.documents.issue', $doc))->assertSessionHasNoErrors();
        $this->assertSame('INV-00001', $doc->fresh()->number, 'the first number, not the second');
        $this->assertSame($before['journals'] + 1, $this->books()['journals']);
        $this->assertSame(1, JournalEntry::query()->forContext($this->ledger)->where('source_type', 'invoice')->where('source_id', (string) $doc->id)->count());
        $this->assertBooksVerify();
    }

    public function test_a_receipt_that_fails_after_the_payment_row_is_written_leaves_nothing_and_the_retry_with_the_same_key_pays_once(): void
    {
        $invoice = $this->issueInvoice($this->acme, [['description' => 'Job', 'quantity' => '1', 'unit_cents' => 200_000, 'tax_code' => 'ZA_VAT_STD']], '2026-11-05');
        $before = $this->books();
        $body = ['party_id' => $this->acme->id, 'cash_account_id' => $this->bank->id, 'amount' => '1000.00', 'paid_on' => '2026-11-10', 'idempotency_key' => 'retry-me', 'allocations' => [$invoice->id => '1000.00']];

        $this->injectFault(Transaction::class, 'creating', 1, fn ($t) => str_starts_with((string) $t->settlement_ref, 'payment:'));    // the cash row, written after the payment
        $this->asOwner()->post(route('sales.payments.store'), $body)->assertStatus(500);
        $this->assertSame($before, $this->books(), 'no payment, no allocation, no cash row, no entry');
        $this->assertSame(0, DB::table('payment_allocations')->where('team_id', $this->company->id)->count());
        $this->assertSame('issued', $invoice->fresh()->status);

        $this->recover();
        $this->asOwner()->post(route('sales.payments.store'), $body)->assertSessionHasNoErrors();
        $this->assertSame($before['payments'] + 1, $this->books()['payments']);
        $this->assertSame('partially_paid', $invoice->fresh()->status);
        $this->assertSame(1, Payment::query()->forContext($this->ledger)->where('idempotency_key', 'retry-me')->count());
        $this->assertBooksVerify();
    }

    public function test_a_supplier_payment_and_a_bill_posting_that_fail_half_way_also_leave_nothing_and_recover(): void
    {
        $this->injectSqlFault('insert into "journal_lines"');                          // the entry and its three lines are written; the bill is not yet marked open
        $this->asOwner()->post(route('purchases.bills.store'), ['party_id' => $this->paperCo->id, 'bill_date' => '2026-11-04', 'supplier_reference' => 'F-1', 'document_type' => 'tax_invoice', 'client_token' => 'f-bill', 'submit_now' => '1',
            'lines' => [['description' => 'Paper', 'gl_account_id' => $this->gl('6190')->id, 'quantity' => '1', 'unit_price' => '1000.00', 'tax_code' => 'ZA_VAT_STD']]]);
        $this->assertSame(0, JournalEntry::query()->forContext($this->ledger)->where('source_type', 'bill')->count(), 'no half-posted bill');
        $this->assertSame(0, $this->balance('ap'));
        $draft = Bill::query()->forContext($this->ledger)->where('supplier_reference', 'F-1')->first();
        $this->assertTrue($draft === null || $draft->status === 'draft', 'at worst the typed bill is kept as a draft');

        $this->recover();
        $bill = $draft ?? Bill::query()->forContext($this->ledger)->where('supplier_reference', 'F-1')->firstOrFail();
        if ($bill->status === 'draft') {
            $this->asOwner()->post(route('purchases.bills.submit', $bill))->assertSessionHasNoErrors();
        }
        $this->assertSame('open', $bill->fresh()->status);
        $this->assertSame(1, JournalEntry::query()->forContext($this->ledger)->where('source_type', 'bill')->count());

        $before = $this->books();
        $pay = ['party_id' => $this->paperCo->id, 'cash_account_id' => $this->bank->id, 'paid_on' => '2026-11-12', 'idempotency_key' => 'sup-retry', 'pay' => ['bill-'.$bill->id => '1150.00']];
        $this->injectFault(Transaction::class, 'creating', 1, fn ($t) => str_starts_with((string) $t->settlement_ref, 'payment:'));
        $this->asOwner()->post(route('purchases.payments.store'), $pay)->assertStatus(500);
        $this->assertSame($before, $this->books());
        $this->assertSame('open', $bill->fresh()->status);
        $this->recover();
        $this->asOwner()->post(route('purchases.payments.store'), $pay)->assertSessionHasNoErrors();
        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertSame(1, Payment::query()->forContext($this->ledger)->where('idempotency_key', 'sup-retry')->count());
        $this->assertBooksVerify();
    }

    public function test_a_repeating_invoice_run_that_dies_part_way_resumes_without_a_duplicate_or_a_gap(): void
    {
        $template = SalesRecurringTemplate::create($this->ledger->ownerAttributes() + [
            'party_id' => $this->acme->id, 'name' => 'Retainer', 'frequency' => 'monthly', 'every' => 1, 'start_date' => '2026-09-30', 'next_run_date' => '2026-09-30', 'mode' => 'issue', 'currency' => 'ZAR', 'terms_days' => 14,
            'status' => 'active', 'created_by' => $this->owner->id, 'lines' => [['description' => 'Monthly retainer', 'quantity' => '1', 'unit_cents' => 500_000, 'tax_code' => 'ZA_VAT_STD']],
        ]);
        $today = CarbonImmutable::parse('2026-11-30');

        $this->injectFault(SalesDocument::class, 'creating', 2);                    // September's is made, October's dies
        try {
            LedgerContext::run($this->ledger, fn () => app(RecurringInvoices::class)->runFor($this->ledger, $today));
        } catch (\Throwable) {
            // a failed run: the scheduler would report it and run again tomorrow
        }
        $afterFailure = SalesDocument::query()->forContext($this->ledger)->where('recurring_template_id', $template->id)->get();
        $this->assertLessThanOrEqual(2, $afterFailure->count());
        $this->assertSame($afterFailure->count(), $afterFailure->pluck('recurring_period')->unique()->count(), 'no period twice');

        $this->recover();
        LedgerContext::run($this->ledger, fn () => app(RecurringInvoices::class)->runFor($this->ledger, $today));
        LedgerContext::run($this->ledger, fn () => app(RecurringInvoices::class)->runFor($this->ledger, $today));           // and again: nothing new
        $docs = SalesDocument::query()->forContext($this->ledger)->where('recurring_template_id', $template->id)->orderBy('issue_date')->get();
        $this->assertCount(3, $docs, 'September, October and November: one each');
        $this->assertSame(3, $docs->pluck('recurring_period')->unique()->count());
        $this->assertSame(['INV-00001', 'INV-00002', 'INV-00003'], $docs->pluck('number')->all(), 'numbers without a gap');
        $this->assertSame(3, JournalEntry::query()->forContext($this->ledger)->where('source_type', 'invoice')->count(), 'one posting each');
        $this->assertBooksVerify();
    }

    public function test_a_repeating_bill_run_that_dies_part_way_resumes_without_a_duplicate(): void
    {
        RecurringBill::query()->create($this->ledger->ownerAttributes() + [
            'party_id' => $this->paperCo->id, 'name' => 'Rent', 'frequency' => 'monthly', 'interval_count' => 1, 'start_on' => '2026-09-01', 'next_run_on' => '2026-09-01', 'status' => 'active', 'days_until_due' => 14, 'currency' => 'ZAR',
            'tax_inclusive' => false, 'document_type' => 'tax_invoice', 'created_by' => $this->owner->id,
            'template' => [['description' => 'Office rent', 'gl_account_id' => $this->gl('6190')->id, 'quantity' => '1', 'unit_price_cents' => 1_000_000, 'tax_code' => 'ZA_VAT_STD']],
        ]);
        $today = CarbonImmutable::parse('2026-11-09');

        $this->injectFault(Bill::class, 'creating', 2);
        try {
            app(RecurringBills::class)->generateDue($this->ledger, $today);
        } catch (\Throwable) {
        }
        $this->recover();
        app(RecurringBills::class)->generateDue($this->ledger, $today);
        $second = app(RecurringBills::class)->generateDue($this->ledger, $today);
        $this->assertSame(0, $second['created']);
        $bills = Bill::query()->forContext($this->ledger)->orderBy('bill_date')->get();
        $this->assertSame(['2026-09-01', '2026-10-01', '2026-11-01'], $bills->map(fn ($b) => $b->bill_date->toDateString())->all(), 'one per period, no gap, no duplicate');
    }

    public function test_a_reminder_run_whose_mail_server_is_down_logs_the_failure_and_never_mails_twice(): void
    {
        $invoice = $this->issueInvoice($this->acme, [['description' => 'Job', 'quantity' => '1', 'unit_cents' => 100_000, 'tax_code' => 'ZA_VAT_STD']], '2026-11-01');
        $due = CarbonImmutable::parse($invoice->due_date->toDateString());
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));
        $first = LedgerContext::run($this->ledger, fn () => app(Reminders::class)->runFor($this->ledger, $due));
        $this->assertSame(1, $first['failed']);

        $again = LedgerContext::run($this->ledger, fn () => app(Reminders::class)->runFor($this->ledger, $due));
        $this->assertSame(0, $again['handed_to_mailer']);
        $this->assertSame(1, SalesReminder::query()->forContext($this->ledger)->count(), 'one log row for the reminder: a failure is on the record and does not multiply');
        $this->assertSame('failed', SalesReminder::query()->forContext($this->ledger)->value('outcome'));
        $this->asOwner()->get(route('sales.reminders.index'))->assertOk();
    }

    public function test_an_export_job_whose_file_write_fails_is_marked_failed_and_the_retry_makes_one_file_and_one_row(): void
    {
        // the queue is synchronous in tests, so asking for the export runs the job at once
        $this->injectFault(ReportExport::class, 'updating', 1, fn ($e) => $e->status === 'done');     // the file is written; the row cannot be marked done
        try {
            app(ExportService::class)->request($this->ledger, $this->owner, 'profit-loss', 'csv', ['from' => '2026-11-01', 'to' => '2026-11-30']);
            $this->fail('the first run was meant to fail');
        } catch (\RuntimeException $e) {
            $this->assertSame('injected fault', $e->getMessage());
        }
        $export = ReportExport::query()->forContext($this->ledger)->firstOrFail();
        $this->assertSame('failed', $export->status, 'the failure is on the record, with the reason');
        $this->assertSame('injected fault', $export->error);

        $this->recover();
        (new GenerateReportExport($export->id, null, $this->company->id))->handle(app(ExportService::class));
        (new GenerateReportExport($export->id, null, $this->company->id))->handle(app(ExportService::class));      // a duplicate delivery of the same job
        $export->refresh();
        $this->assertSame('done', $export->status);
        $this->assertCount(1, Storage::disk('local')->allFiles('report-exports'));
        $this->assertSame(1, ReportExport::query()->forContext($this->ledger)->count());
        $this->assertSame(2, $export->attempts, 'two real attempts, and the duplicate delivery did nothing');
        $this->assertSame(hash('sha256', Storage::disk('local')->get($export->path)), $export->sha256);
    }

    public function test_a_row_written_around_the_app_by_a_crashed_script_is_found_and_posted_exactly_once_by_the_sweeper(): void
    {
        $id = DB::table('transactions')->insertGetId([
            'account_id' => $this->bank->id, 'team_id' => $this->company->id, 'description' => 'Raw row', 'amount' => '12.34', 'amount_cents' => 1_234, 'currency' => 'ZAR', 'type' => 'expense', 'date' => '2026-11-12',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertFalse(app(\App\Services\Accounting\GlVerifier::class)->verify($this->ledger)['ok'], 'verify notices the row with no posting');

        $this->artisan('finance:gl:post-pending', ['--ledger' => $this->ledger->key()])->assertExitCode(0);
        $this->artisan('finance:gl:post-pending', ['--ledger' => $this->ledger->key()])->assertExitCode(0);
        $this->assertSame(1, JournalEntry::query()->forContext($this->ledger)->where('source_type', 'transaction')->where('source_id', (string) $id)->where('status', 'posted')->count());
        $this->assertBooksVerify();
    }

    public function test_a_notification_run_that_fails_to_mail_is_not_repeated_into_duplicates(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));
        $this->issueInvoice($this->acme, [['description' => 'Late job', 'quantity' => '1', 'unit_cents' => 100_000, 'tax_code' => 'ZA_VAT_STD']], '2026-09-01');
        $this->artisan('finance:notifications:run')->assertExitCode(0);
        $count = \App\Models\Intelligence\FinanceNotification::query()->forContext($this->ledger)->count();
        $this->artisan('finance:notifications:run')->assertExitCode(0);
        $this->assertSame($count, \App\Models\Intelligence\FinanceNotification::query()->forContext($this->ledger)->count(), 'the second run adds none');
        $this->assertGreaterThan(0, $count);
    }
}
