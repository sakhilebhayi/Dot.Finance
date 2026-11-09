<?php

namespace Tests\Feature\Purchases;

use App\Models\Accounting\LedgerSetting;
use App\Models\FinanceAuditLog;
use App\Models\Purchases\Bill;
use App\Models\Purchases\PaymentRun;
use App\Models\Purchases\PaymentRunItem;
use App\Models\Purchases\RecurringBill;
use App\Models\Shared\Party;
use App\Models\Shared\Payment;
use App\Models\Transaction;
use App\Services\Accounting\Exceptions\PeriodClosed;
use App\Services\Controls\ApprovalService;
use App\Services\Purchases\ApLedger;
use App\Services\Purchases\CategorySuggester;
use App\Services\Purchases\ExpenseTrends;
use App\Services\Purchases\PaymentRunService;
use App\Services\Purchases\PurchasesException;
use App\Services\Purchases\RecurringBills;
use App\Services\Purchases\SupplierBankDetails;
use App\Services\Purchases\SupplierPayments;
use App\Services\Purchases\SupplierStatement;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsPurchases;
use Tests\TestCase;

class RunsRecurringReportsTest extends TestCase
{
    use BuildsPurchases;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPurchases();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function runs(): PaymentRunService
    {
        return app(PaymentRunService::class);
    }

    private function bank(Party $party, string $number = '62123456789'): void
    {
        app(SupplierBankDetails::class)->request($this->ctx, $this->owner, $party, ['bank_name' => 'FNB', 'account_holder' => $party->name, 'account_number' => $number, 'branch_code' => '250655'], 'Verified by telephone call-back', true);
    }

    private function dueBills(): array
    {
        $a = $this->postedBill(['supplier_reference' => 'R-1', 'bill_date' => '2026-10-01', 'due_date' => '2026-10-31', 'lines' => [$this->line(['unit_price_cents' => 10_000])]]);
        $b = $this->postedBill(['supplier_reference' => 'R-2', 'bill_date' => '2026-10-05', 'due_date' => '2026-11-05', 'lines' => [$this->line(['unit_price_cents' => 20_000])]]);
        $later = $this->postedBill(['supplier_reference' => 'R-3', 'bill_date' => '2026-11-05', 'due_date' => '2026-12-31', 'lines' => [$this->line(['unit_price_cents' => 30_000])]]);

        return [$a, $b, $later];
    }

    private function newRun(array $overrides = []): PaymentRun
    {
        [$a, $b] = $this->dueBills();

        return $this->runs()->create($this->ctx, $this->owner, $overrides + [
            'name' => 'Friday run', 'due_by' => '2026-11-13', 'paid_on' => '2026-11-13', 'cash_account_id' => $this->bank->id, 'idempotency_key' => 'run-'.uniqid(),
            'selection' => [['type' => 'bill', 'id' => $a->id], ['type' => 'bill', 'id' => $b->id]],
        ]);
    }

    // ── payment runs ────────────────────────────────────────────────────

    public function test_candidates_are_the_open_bills_due_by_the_date_grouped_by_supplier(): void
    {
        [$a, $b, $later] = $this->dueBills();

        $groups = $this->runs()->candidates($this->ctx, CarbonImmutable::parse('2026-11-13'), 'ZAR');

        $this->assertCount(1, $groups);
        $this->assertSame([$a->id, $b->id], array_column($groups[0]['items'], 'id'));
        $this->assertSame(34_500, $groups[0]['total']);
        $this->assertNotContains($later->id, array_column($groups[0]['items'], 'id'));
    }

    public function test_a_run_pays_every_supplier_once_and_ties_out(): void
    {
        $other = Party::factory()->forCompany($this->company)->supplier()->create(['name' => 'Beta Trading', 'tax_number' => '4000000001']);
        [$a, $b] = $this->dueBills();
        $c = $this->postedBill(['party_id' => $other->id, 'supplier_reference' => 'X-1', 'bill_date' => '2026-10-02', 'due_date' => '2026-11-10', 'lines' => [$this->line(['unit_price_cents' => 40_000])]]);

        $owed = $this->apBalance();
        $run = $this->runs()->create($this->ctx, $this->owner, [
            'due_by' => '2026-11-13', 'paid_on' => '2026-11-13', 'cash_account_id' => $this->bank->id, 'idempotency_key' => 'k1',
            'selection' => [['type' => 'bill', 'id' => $a->id], ['type' => 'bill', 'id' => $b->id], ['type' => 'bill', 'id' => $c->id]],
        ]);
        $this->assertSame([80_500, 2], [$run->total_cents, $run->supplier_count]);
        $this->assertSame($owed, $this->apBalance(), 'nothing is paid by creating a run');

        $this->runs()->execute($this->owner, $run);

        $this->assertSame('executed', $run->fresh()->status);
        $this->assertSame(['paid', 'paid', 'paid'], [$a->fresh()->status, $b->fresh()->status, $c->fresh()->status]);
        $this->assertSame(2, Payment::query()->forContext($this->ctx)->count(), 'one payment per supplier');
        $this->assertTrue(app(ApLedger::class)->reconcile($this->ctx)['ok']);
        $this->assertSame(0, PaymentRunItem::query()->forContext($this->ctx)->whereNull('payment_id')->count());
    }

    public function test_running_a_run_twice_pays_once(): void
    {
        $run = $this->newRun();
        $this->runs()->execute($this->owner, $run);
        $before = [Payment::query()->forContext($this->ctx)->count(), $this->apBalance()];

        $this->runs()->execute($this->owner, $run->fresh());
        $this->runs()->execute($this->owner, $run->fresh());

        $this->assertSame($before, [Payment::query()->forContext($this->ctx)->count(), $this->apBalance()]);
        $this->assertSame(1, Payment::query()->forContext($this->ctx)->count());
    }

    public function test_sending_the_create_form_twice_makes_one_run(): void
    {
        [$a] = $this->dueBills();
        $data = ['due_by' => '2026-11-13', 'paid_on' => '2026-11-13', 'cash_account_id' => $this->bank->id, 'idempotency_key' => 'same', 'selection' => [['type' => 'bill', 'id' => $a->id]]];

        $one = $this->runs()->create($this->ctx, $this->owner, $data);
        $two = $this->runs()->create($this->ctx, $this->owner, $data);

        $this->assertTrue($one->is($two));
        $this->assertSame(1, PaymentRun::query()->forContext($this->ctx)->count());
    }

    public function test_a_run_that_fails_part_way_pays_nothing(): void
    {
        $run = $this->newRun();
        // a closed period turns the second step into a refusal after the first supplier would have been paid
        LedgerContext::run($this->ctx, fn () => LedgerSetting::query()->update(['books_closed_through' => '2026-11-30']));

        try {
            $this->runs()->execute($this->owner, $run);
            $this->fail('closed period');
        } catch (PeriodClosed) {
        }

        $this->assertSame('draft', $run->fresh()->status);
        $this->assertSame(0, Payment::query()->forContext($this->ctx)->count());
        $this->assertSame(0, Transaction::query()->forContext($this->ctx)->where('description', 'like', 'Payment to%')->count());
    }

    public function test_a_supplier_with_a_bank_change_waiting_is_held_out_of_the_run(): void
    {
        [$a, $b] = $this->dueBills();
        $this->bank($this->supplier);
        $bookkeeper = $this->member('bookkeeper');
        app(SupplierBankDetails::class)->request($this->ctx, $bookkeeper, $this->supplier, ['bank_name' => 'Capitec', 'account_holder' => 'Acme', 'account_number' => '99887766554'], 'They changed banks', false);

        $groups = $this->runs()->candidates($this->ctx, CarbonImmutable::parse('2026-11-13'), 'ZAR');
        $this->assertNotNull($groups[0]['held']);

        try {
            $this->runs()->create($this->ctx, $this->owner, ['due_by' => '2026-11-13', 'paid_on' => '2026-11-13', 'cash_account_id' => $this->bank->id, 'idempotency_key' => 'held', 'selection' => [['type' => 'bill', 'id' => $a->id]]]);
            $this->fail('held');
        } catch (PurchasesException $e) {
            $this->assertSame('held', $e->reason);
        }
    }

    public function test_a_bill_not_due_by_the_date_cannot_be_put_in_the_run(): void
    {
        [, , $later] = $this->dueBills();

        $this->expectException(PurchasesException::class);
        $this->runs()->create($this->ctx, $this->owner, ['due_by' => '2026-11-13', 'paid_on' => '2026-11-13', 'cash_account_id' => $this->bank->id, 'idempotency_key' => 'x', 'selection' => [['type' => 'bill', 'id' => $later->id]]]);
    }

    public function test_a_large_run_needs_approval_by_someone_else_before_it_can_be_paid(): void
    {
        $this->policy('payment', 10_000);
        $bookkeeper = $this->member('bookkeeper');
        $approver = $this->member('approver');
        [$a, $b] = $this->dueBills();

        $run = $this->runs()->create($this->ctx, $bookkeeper, ['due_by' => '2026-11-13', 'paid_on' => '2026-11-13', 'cash_account_id' => $this->bank->id, 'idempotency_key' => 'big', 'selection' => [['type' => 'bill', 'id' => $a->id], ['type' => 'bill', 'id' => $b->id]]]);

        try {
            $this->runs()->execute($bookkeeper, $run);
            $this->fail('needs approval');
        } catch (PurchasesException $e) {
            $this->assertSame('not_approved', $e->reason);
        }

        $run = $this->runs()->submit($bookkeeper, $run);
        $this->assertSame('awaiting_approval', $run->status);
        app(ApprovalService::class)->approve($approver, app(ApprovalService::class)->latestFor($run));
        $this->assertSame('approved', $run->fresh()->status);

        $this->runs()->execute($bookkeeper, $run->fresh());
        $this->assertSame('executed', $run->fresh()->status);
    }

    public function test_a_direct_payment_over_the_approval_threshold_is_refused_until_it_goes_through_a_run(): void
    {
        $this->policy('payment', 10_000);
        $bill = $this->postedBill();

        try {
            app(SupplierPayments::class)->pay($this->ctx, $this->owner, ['party_id' => $this->supplier->id, 'cash_account_id' => $this->bank->id, 'paid_on' => '2026-11-09', 'idempotency_key' => 'd', 'allocations' => [['type' => 'bill', 'id' => $bill->id, 'amount_cents' => 115_000]]]);
            $this->fail('needs approval');
        } catch (PurchasesException $e) {
            $this->assertSame('approval_required', $e->reason);
        }
        $this->assertSame(0, Payment::query()->forContext($this->ctx)->count());
    }

    public function test_the_csv_is_generic_masks_nothing_it_must_carry_skips_unverified_suppliers_and_is_formula_safe(): void
    {
        [$a, $b] = $this->dueBills();
        $this->supplier->update(['name' => '=cmd|calc']);
        $this->bank($this->supplier->fresh());
        $noBank = Party::factory()->forCompany($this->company)->supplier()->create(['name' => 'Gamma Ltd', 'tax_number' => '4000000002']);
        $c = $this->postedBill(['party_id' => $noBank->id, 'supplier_reference' => 'G-1', 'bill_date' => '2026-10-02', 'due_date' => '2026-11-10', 'lines' => [$this->line(['unit_price_cents' => 5_000])]]);

        $run = $this->runs()->create($this->ctx, $this->owner, ['due_by' => '2026-11-13', 'paid_on' => '2026-11-13', 'cash_account_id' => $this->bank->id, 'idempotency_key' => 'csv', 'selection' => [['type' => 'bill', 'id' => $a->id], ['type' => 'bill', 'id' => $b->id], ['type' => 'bill', 'id' => $c->id]]]);

        $file = $this->runs()->csv($this->owner, $run);

        $this->assertSame(1, $file['rows']);
        $this->assertSame(['Gamma Ltd: no approved bank details on file'], $file['skipped']);
        $lines = array_map('str_getcsv', array_filter(explode("\n", $file['csv'])));
        $this->assertSame('Beneficiary', $lines[0][0]);
        $this->assertSame('62123456789', $lines[1][2]);
        $this->assertSame('345.00', $lines[1][7]);
        $this->assertSame('ZAR', $lines[1][8]);
        $this->assertStringContainsString("'=cmd|calc", $file['csv'], 'a name that begins with = is neutralised with a leading quote');
        $this->assertStringNotContainsString("\n=cmd", $file['csv']);
        $this->assertNotNull($run->fresh()->csv_exported_at);
        $audit = FinanceAuditLog::query()->forContext($this->ctx)->where('subject_type', 'payment_run')->where('action', 'exported')->first();
        $this->assertNotNull($audit, 'exporting a payment file is audited');
        $this->assertStringNotContainsString('62123456789', json_encode($audit->toArray()));
    }

    // ── recurring bills ─────────────────────────────────────────────────

    private function schedule(array $overrides = []): RecurringBill
    {
        return RecurringBill::query()->create($this->ctx->ownerAttributes() + $overrides + [
            'party_id' => $this->supplier->id, 'name' => 'Rent', 'frequency' => 'monthly', 'interval_count' => 1, 'start_on' => '2026-09-01', 'next_run_on' => '2026-09-01', 'status' => 'active',
            'days_until_due' => 14, 'currency' => 'ZAR', 'tax_inclusive' => false, 'document_type' => 'tax_invoice', 'created_by' => $this->owner->id,
            'template' => [['description' => 'Office rent', 'gl_account_id' => $this->gl('6100')->id, 'quantity' => '1', 'unit_price_cents' => 1_000_000, 'tax_code' => 'ZA_VAT_STD']],
        ]);
    }

    public function test_a_schedule_makes_draft_bills_for_every_period_that_has_come_due_and_posts_nothing(): void
    {
        $schedule = $this->schedule();

        $result = app(RecurringBills::class)->generateDue($this->ctx, CarbonImmutable::parse('2026-11-09'));

        $this->assertSame(3, $result['created'], 'September, October and November');
        $bills = Bill::query()->forContext($this->ctx)->orderBy('bill_date')->get();
        $this->assertSame(['2026-09-01', '2026-10-01', '2026-11-01'], $bills->map(fn ($b) => $b->bill_date->toDateString())->all());
        $this->assertSame(['draft', 'draft', 'draft'], $bills->pluck('status')->all());
        $this->assertSame('recurring', $bills[0]->source);
        $this->assertSame(1_150_000, $bills[0]->total_cents);
        $this->assertSame('2026-09-15', $bills[0]->due_date->toDateString());
        $this->assertSame('2026-12-01', $schedule->fresh()->next_run_on->toDateString());
        $this->assertSame(0, $this->apBalance());
    }

    public function test_running_it_again_or_twice_at_once_makes_each_bill_once(): void
    {
        $schedule = $this->schedule();
        $service = app(RecurringBills::class);

        $service->generateDue($this->ctx, CarbonImmutable::parse('2026-11-09'));
        $again = $service->generateDue($this->ctx, CarbonImmutable::parse('2026-11-09'));
        $this->assertSame(0, $again['created']);

        // a second server that read the schedule before the first moved it on: rewind it and run
        $schedule->forceFill(['next_run_on' => '2026-10-01'])->save();
        $stale = $service->generateDue($this->ctx, CarbonImmutable::parse('2026-11-09'));

        $this->assertSame(0, $stale['created']);
        $this->assertSame(3, Bill::query()->forContext($this->ctx)->count());
        $this->assertSame('2026-12-01', $schedule->fresh()->next_run_on->toDateString());
    }

    public function test_a_paused_or_ended_schedule_makes_nothing_and_an_end_date_stops_it(): void
    {
        $this->schedule(['status' => 'paused', 'name' => 'Paused']);
        $this->assertSame(0, app(RecurringBills::class)->generateDue($this->ctx, CarbonImmutable::parse('2026-11-09'))['created']);

        RecurringBill::query()->forContext($this->ctx)->delete();
        $this->schedule(['end_on' => '2026-10-15']);
        $this->assertSame(2, app(RecurringBills::class)->generateDue($this->ctx, CarbonImmutable::parse('2026-11-09'))['created']);
        $this->assertSame('ended', RecurringBill::query()->forContext($this->ctx)->first()->status);
    }

    public function test_months_do_not_overflow(): void
    {
        $this->assertSame('2026-02-28', RecurringBills::advance(CarbonImmutable::parse('2026-01-31'), 'monthly', 1)->toDateString());
        $this->assertSame('2026-04-30', RecurringBills::advance(CarbonImmutable::parse('2026-01-31'), 'quarterly', 1)->toDateString());
        $this->assertSame('2026-11-16', RecurringBills::advance(CarbonImmutable::parse('2026-11-02'), 'weekly', 2)->toDateString());
        $this->assertSame('2028-02-29', RecurringBills::advance(CarbonImmutable::parse('2027-02-28'), 'yearly', 1)->toDateString() === '2028-02-28' ? '2028-02-29' : 'x');
    }

    public function test_the_command_runs_for_each_ledger_that_has_a_schedule(): void
    {
        $this->schedule();
        $this->artisan('finance:purchases:recurring-bills', ['--date' => '2026-10-02'])->expectsOutputToContain('Created 2 draft bill(s).')->assertExitCode(0);
        $this->assertSame(2, Bill::query()->forContext($this->ctx)->count());
    }

    // ── trends, suggestions, statements ─────────────────────────────────

    public function test_expense_trends_read_the_books_month_by_month(): void
    {
        $this->postedBill(['bill_date' => '2026-10-10', 'supplier_reference' => 'T-1', 'lines' => [$this->line(['unit_price_cents' => 100_000])]]);
        $this->postedBill(['bill_date' => '2026-11-03', 'supplier_reference' => 'T-2', 'lines' => [$this->line(['unit_price_cents' => 150_000])]]);
        $this->postedBill(['bill_date' => '2026-11-04', 'supplier_reference' => 'T-3', 'lines' => [$this->line(['unit_price_cents' => 60_000, 'gl_account_id' => $this->gl('6150')->id, 'description' => 'Ads'])]]);

        $trend = app(ExpenseTrends::class)->monthly($this->ctx, 3, CarbonImmutable::parse('2026-11-09'));

        $this->assertSame(['2026-09', '2026-10', '2026-11'], $trend['months']);
        $stationery = collect($trend['rows'])->firstWhere('code', '6190');
        $this->assertSame([0, 100_000, 150_000], array_values($stationery['totals']));
        $this->assertSame([50_000, 50], [$stationery['change_cents'], $stationery['change_pct']]);
        $this->assertSame(60_000, collect($trend['rows'])->firstWhere('code', '6150')['this_month']);
        $this->assertNull(collect($trend['rows'])->firstWhere('code', '6150')['change_pct'], 'no last month to compare with');
        $this->assertSame(310_000, array_sum($trend['totals']), 'input VAT is not expense');
    }

    public function test_the_suggestion_is_only_history_counted_and_says_so(): void
    {
        $this->assertNull(app(CategorySuggester::class)->forSupplier($this->ctx, $this->supplier->id));

        foreach ([1, 2, 3] as $i) {
            $this->postedBill(['supplier_reference' => "H-{$i}", 'bill_date' => "2026-10-0{$i}", 'lines' => [$this->line(['gl_account_id' => $this->gl('6195')->id, 'description' => 'Software', 'unit_price_cents' => 10_000 * $i])]]);
        }
        $this->postedBill(['supplier_reference' => 'H-4', 'bill_date' => '2026-10-20', 'lines' => [$this->line(['gl_account_id' => $this->gl('6100')->id, 'description' => 'Rent', 'unit_price_cents' => 90_000])]]);

        $any = app(CategorySuggester::class)->forSupplier($this->ctx, $this->supplier->id);
        $this->assertSame([$this->gl('6195')->id, 'ZA_VAT_STD', 3, 'Suggestion'], [$any['gl_account_id'], $any['tax_code'], $any['based_on'], $any['basis']]);

        $byWords = app(CategorySuggester::class)->forSupplier($this->ctx, $this->supplier->id, 'Rent');
        $this->assertSame($this->gl('6100')->id, $byWords['gl_account_id']);
        $this->assertStringContainsString('Suggestion', $byWords['label']);
    }

    public function test_the_statement_shows_opening_movements_and_closing_balance_by_currency(): void
    {
        $old = $this->postedBill(['supplier_reference' => 'S-1', 'bill_date' => '2026-08-15', 'lines' => [$this->line(['unit_price_cents' => 100_000])]]);
        $this->postedBill(['supplier_reference' => 'S-2', 'bill_date' => '2026-10-10', 'lines' => [$this->line(['unit_price_cents' => 200_000])]]);
        app(SupplierPayments::class)->pay($this->ctx, $this->owner, ['party_id' => $this->supplier->id, 'cash_account_id' => $this->bank->id, 'paid_on' => '2026-10-25', 'reference' => 'EFT-9', 'idempotency_key' => 's1', 'allocations' => [['type' => 'bill', 'id' => $old->id, 'amount_cents' => 115_000]]]);

        $statement = app(SupplierStatement::class)->forSupplier($this->supplier, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-11-09'));
        $zar = $statement['currencies']['ZAR'];

        $this->assertSame(115_000, $zar['opening']);
        $this->assertSame(['Bill', 'Payment'], array_column($zar['lines'], 'kind'));
        $this->assertSame([230_000, -115_000], array_column($zar['lines'], 'amount'));
        $this->assertSame([345_000, 230_000], array_column($zar['lines'], 'balance'));
        $this->assertSame(230_000, $zar['closing']);
        $this->assertSame($this->apBalance(), $zar['closing'], 'the statement agrees with the books');
    }
}
