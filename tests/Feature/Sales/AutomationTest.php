<?php

namespace Tests\Feature\Sales;

use App\Mail\SalesReminderMail;
use App\Models\Sales\SalesDocument;
use App\Models\Sales\SalesRecurringTemplate;
use App\Models\Sales\SalesReminder;
use App\Models\Shared\Party;
use App\Services\Sales\DocumentIssuer;
use App\Services\Sales\ReceiptService;
use App\Services\Sales\RecurringInvoices;
use App\Services\Sales\Reminders;
use App\Services\Sales\SalesProfiles;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsSales;
use Tests\TestCase;

class AutomationTest extends TestCase
{
    use BuildsSales;
    use RefreshDatabase;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = CarbonImmutable::today();
    }

    /** An invoice issued $age days ago, due $dueIn days from today. */
    private function invoiceDue(int $dueIn, ?Party $customer = null): SalesDocument
    {
        $issue = min(0, $dueIn - 30);
        $draft = $this->draft([$this->line('Work', '1', 100_000)], ['issue_date' => $this->today->addDays($issue)->toDateString(), 'due_date' => $this->today->addDays($dueIn)->toDateString()], $customer);

        return LedgerContext::run($this->ledger, fn () => app(DocumentIssuer::class)->issue($draft, $this->owner)['document']);
    }

    // ---- reminders ----

    public function test_reminders_go_before_on_and_after_the_due_date_once_each(): void
    {
        $this->setUpSales();
        Mail::fake();
        LedgerContext::run($this->ledger, fn () => app(SalesProfiles::class)->save($this->ledger, ['reminder_before_days' => 3, 'reminder_after_days' => [7, 14]]));
        $this->invoiceDue(3);     // before
        $this->invoiceDue(0);     // on
        $this->invoiceDue(-7);    // after 7
        $this->invoiceDue(-14);   // after 14
        $this->invoiceDue(-10);   // none of the offsets
        $this->invoiceDue(10);    // not yet

        $first = LedgerContext::run($this->ledger, fn () => app(Reminders::class)->runFor($this->ledger, $this->today));
        $second = LedgerContext::run($this->ledger, fn () => app(Reminders::class)->runFor($this->ledger, $this->today));

        $this->assertSame(4, $first['due']);
        $this->assertSame(4, $first['handed_to_mailer']);
        $this->assertSame(4, $second['already_logged'], 'running it again sends nothing');
        $this->assertSame(0, $second['handed_to_mailer']);
        Mail::assertSent(SalesReminderMail::class, 4);
        $this->assertSame(4, SalesReminder::query()->forContext($this->ledger)->where('outcome', 'handed_to_mailer')->count());
    }

    public function test_the_reminder_says_what_is_owed_and_is_not_sent_for_a_paid_invoice(): void
    {
        $this->setUpSales();
        Mail::fake();
        $paid = $this->invoiceDue(0);
        $owing = $this->invoiceDue(0);
        LedgerContext::run($this->ledger, fn () => app(ReceiptService::class)->receive($this->ledger, $this->owner, [
            'party_id' => $this->customer->id, 'cash_account_id' => $this->bank->id, 'amount_cents' => 115_000, 'paid_on' => now()->toDateString(), 'idempotency_key' => 'p',
            'allocations' => [['document_id' => $paid->id, 'amount_cents' => 115_000]],
        ]));

        LedgerContext::run($this->ledger, fn () => app(Reminders::class)->runFor($this->ledger, $this->today));

        Mail::assertSent(SalesReminderMail::class, 1);
        Mail::assertSent(SalesReminderMail::class, fn ($mail) => $mail->document->is($owing) && $mail->owedCents === 115_000 && $mail->hasTo('ap@acme.test'));
    }

    public function test_an_opted_out_customer_and_one_without_an_address_are_logged_not_mailed(): void
    {
        $this->setUpSales();
        Mail::fake();
        $this->invoiceDue(0);
        $noEmail = LedgerContext::run($this->ledger, fn () => Party::create($this->ledger->ownerAttributes() + ['type' => 'customer', 'name' => 'No Email Co', 'status' => 'active', 'billing_address' => ['line1' => '1 X']]));
        $this->invoiceDue(0, $noEmail);
        LedgerContext::run($this->ledger, fn () => app(Reminders::class)->setOptOut($this->customer, true));

        $counts = LedgerContext::run($this->ledger, fn () => app(Reminders::class)->runFor($this->ledger, $this->today));

        $this->assertSame(1, $counts['skipped_opt_out']);
        $this->assertSame(1, $counts['skipped_no_email']);
        Mail::assertNothingSent();
        $this->assertSame(2, SalesReminder::query()->forContext($this->ledger)->count(), 'both are in the log with their reason');
    }

    public function test_a_failing_mailer_is_logged_as_failed_and_not_retried_into_a_duplicate(): void
    {
        $this->setUpSales();
        $this->invoiceDue(0);
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        $counts = LedgerContext::run($this->ledger, fn () => app(Reminders::class)->runFor($this->ledger, $this->today));
        $this->assertSame(1, $counts['failed']);

        $again = LedgerContext::run($this->ledger, fn () => app(Reminders::class)->runFor($this->ledger, $this->today));
        $this->assertSame(1, $again['already_logged']);
    }

    public function test_the_log_row_is_unique_per_invoice_stage_and_offset_in_the_database(): void
    {
        $this->setUpSales();
        $d = $this->invoiceDue(0);
        $row = ['document_id' => $d->id, 'party_id' => $this->customer->id, 'stage' => 'on_due', 'offset_days' => 0, 'scheduled_for' => $this->today->toDateString(), 'outcome' => 'pending'];
        SalesReminder::query()->create($this->ledger->ownerAttributes() + $row);

        $this->expectException(UniqueConstraintViolationException::class);
        SalesReminder::query()->create($this->ledger->ownerAttributes() + $row);
    }

    public function test_the_command_visits_only_ledgers_with_reminders_on(): void
    {
        $this->setUpSales();
        Mail::fake();
        $this->invoiceDue(0);

        $this->artisan('sales:send-reminders')->assertExitCode(0);
        Mail::assertSent(SalesReminderMail::class, 1);

        LedgerContext::run($this->ledger, fn () => app(SalesProfiles::class)->save($this->ledger, ['reminders_enabled' => false]));
        $this->artisan('sales:send-reminders', ['--date' => $this->today->addDay()->toDateString()])->assertExitCode(0);
        Mail::assertSent(SalesReminderMail::class, 1);
    }

    // ---- repeating invoices ----

    private function template(array $over = []): SalesRecurringTemplate
    {
        return SalesRecurringTemplate::create($this->ledger->ownerAttributes() + $over + [
            'party_id' => $this->customer->id, 'name' => 'Retainer', 'frequency' => 'monthly', 'every' => 1, 'start_date' => $this->today->subMonths(2)->toDateString(),
            'next_run_date' => $this->today->subMonths(2)->toDateString(), 'mode' => 'draft', 'currency' => 'ZAR', 'terms_days' => 14, 'status' => 'active', 'created_by' => $this->owner->id,
            'lines' => [['description' => 'Monthly retainer', 'quantity' => '1', 'unit_cents' => 500_000, 'tax_code' => 'ZA_VAT_STD']],
        ]);
    }

    public function test_a_template_catches_up_one_invoice_per_period_and_never_twice(): void
    {
        $this->setUpSales();
        $t = $this->template();

        $a = LedgerContext::run($this->ledger, fn () => app(RecurringInvoices::class)->runFor($this->ledger, $this->today));
        $b = LedgerContext::run($this->ledger, fn () => app(RecurringInvoices::class)->runFor($this->ledger, $this->today));

        $this->assertSame(3, $a['created'], 'two months ago, last month and this month');
        $this->assertSame(0, $b['created']);
        $docs = SalesDocument::query()->forContext($this->ledger)->where('recurring_template_id', $t->id)->orderBy('issue_date')->get();
        $this->assertCount(3, $docs);
        $this->assertSame($docs->pluck('recurring_period')->unique()->count(), 3);
        $this->assertSame('draft', $docs[0]->status);
        $this->assertSame($docs[0]->issue_date->addDays(14)->toDateString(), $docs[0]->due_date->toDateString());
        $this->assertSame(575_000, (int) $docs[0]->total_cents);
        $this->assertSame($this->today->addMonthNoOverflow()->toDateString() >= $t->refresh()->next_run_date->toDateString(), true);
        $this->assertGreaterThan($this->today->toDateString(), $t->next_run_date->toDateString());
    }

    public function test_the_database_refuses_a_second_invoice_for_the_same_template_and_period(): void
    {
        $this->setUpSales();
        $t = $this->template();
        LedgerContext::run($this->ledger, fn () => app(RecurringInvoices::class)->runFor($this->ledger, $this->today));
        $first = SalesDocument::query()->forContext($this->ledger)->where('recurring_template_id', $t->id)->firstOrFail();
        $second = $this->draft();

        $this->expectException(QueryException::class);
        DB::table('sales_documents')->where('id', $second->id)->update(['recurring_template_id' => $t->id, 'recurring_period' => $first->recurring_period]);
    }

    public function test_an_issuing_template_issues_with_numbers_and_posts(): void
    {
        $this->setUpSales();
        $this->template(['mode' => 'issue', 'start_date' => $this->today->toDateString(), 'next_run_date' => $this->today->toDateString()]);

        $out = LedgerContext::run($this->ledger, fn () => app(RecurringInvoices::class)->runFor($this->ledger, $this->today));

        $this->assertSame(1, $out['issued']);
        $doc = SalesDocument::query()->forContext($this->ledger)->firstOrFail();
        $this->assertSame('INV-00001', $doc->number);
        $this->assertSame(575_000, $this->glBalance('ar'));
        $this->assertBooksBalance();
    }

    public function test_an_invoice_that_cannot_be_issued_is_left_as_a_draft_with_the_reason(): void
    {
        $this->setUpSales();
        LedgerContext::run($this->ledger, fn () => app(SalesProfiles::class)->save($this->ledger, ['vat_number' => null]));
        $this->template(['mode' => 'issue', 'start_date' => $this->today->toDateString(), 'next_run_date' => $this->today->toDateString()]);

        $out = LedgerContext::run($this->ledger, fn () => app(RecurringInvoices::class)->runFor($this->ledger, $this->today));

        $this->assertSame(1, $out['left_as_draft']);
        $doc = SalesDocument::query()->forContext($this->ledger)->firstOrFail();
        $this->assertSame('draft', $doc->status);
        $this->assertStringContainsString('Not issued automatically', $doc->approval_note);
        $this->assertSame(0, SalesDocument::query()->forContext($this->ledger)->count() - 1);
    }

    public function test_a_paused_or_ended_template_makes_nothing_and_an_end_date_ends_it(): void
    {
        $this->setUpSales();
        $paused = $this->template(['status' => 'paused']);
        $ending = $this->template(['name' => 'Ends', 'end_date' => $this->today->subMonth()->toDateString()]);

        $out = LedgerContext::run($this->ledger, fn () => app(RecurringInvoices::class)->runFor($this->ledger, $this->today));

        $this->assertSame(0, SalesDocument::query()->forContext($this->ledger)->where('recurring_template_id', $paused->id)->count());
        $this->assertSame(2, SalesDocument::query()->forContext($this->ledger)->where('recurring_template_id', $ending->id)->count(), 'only the periods up to the end date');
        $this->assertSame('ended', $ending->refresh()->status);
        $this->assertSame(1, $out['templates']);
    }

    public function test_month_end_start_dates_do_not_drift(): void
    {
        $this->setUpSales();
        $t = $this->template(['start_date' => '2026-01-31', 'next_run_date' => '2026-01-31']);
        $service = app(RecurringInvoices::class);

        $feb = $service->next($t, CarbonImmutable::parse('2026-01-31'));
        $mar = $service->next($t, $feb);

        $this->assertSame('2026-02-28', $feb->toDateString());
        $this->assertSame('2026-03-31', $mar->toDateString(), 'back to the 31st, not stuck on the 28th');
    }

    public function test_the_recurring_command_runs_for_every_ledger(): void
    {
        $this->setUpSales();
        $this->template(['start_date' => $this->today->toDateString(), 'next_run_date' => $this->today->toDateString()]);

        $this->artisan('sales:run-recurring')->assertExitCode(0);
        $this->artisan('sales:run-recurring')->assertExitCode(0);

        $this->assertSame(1, SalesDocument::query()->forContext($this->ledger)->count());
    }
}
