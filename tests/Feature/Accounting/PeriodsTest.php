<?php

namespace Tests\Feature\Accounting;

use App\Actions\Jetstream\DeleteUser;
use App\Models\Account;
use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\LedgerSetting;
use App\Models\Category;
use App\Models\FinanceAuditLog;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\Chart;
use App\Services\Accounting\ChartTemplates;
use App\Services\Accounting\Exceptions\AccountingException;
use App\Services\Accounting\Exceptions\PeriodClosed;
use App\Services\Accounting\GlQuery;
use App\Services\Accounting\GlVerifier;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\Periods;
use App\Services\Accounting\PostingLine;
use App\Services\Accounting\PostingRequest;
use App\Services\Accounting\TrialBalance;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsAccounting;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\TestCase;

class PeriodsTest extends TestCase
{
    use BuildsAccounting;
    use BuildsCompanyLedgers;
    use RefreshDatabase;

    private User $user;

    private LedgerContext $ctx;

    private Periods $periods;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->user();
        $this->ctx = $this->personalContext($this->user);
        $this->periods = app(Periods::class);

        // A financial year of March to February.
        $bank = Account::factory()->create(['user_id' => $this->user->id, 'opening_balance_cents' => 0, 'opening_balance_date' => '2025-03-01']);
        $sales = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Sales', 'type' => 'income']);
        $rent = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Rent', 'type' => 'expense']);
        Transaction::factory()->create(['account_id' => $bank->id, 'category_id' => $sales->id, 'type' => 'income', 'amount_cents' => 10_000, 'date' => '2025-04-10']);
        Transaction::factory()->create(['account_id' => $bank->id, 'category_id' => $rent->id, 'type' => 'expense', 'amount_cents' => 4_000, 'date' => '2025-06-10']);
        Transaction::factory()->create(['account_id' => $bank->id, 'category_id' => $sales->id, 'type' => 'income', 'amount_cents' => 2_500, 'date' => '2026-03-05']); // the next year
        $this->enableAccounting($this->ctx, 'ZAR', null, 3);
    }

    private function closeThrough(string $date): int
    {
        return LedgerContext::run($this->ctx, fn () => $this->periods->closeThrough($this->ctx, CarbonImmutable::parse($date), $this->user));
    }

    private function trial(?string $asOf = null): array
    {
        return LedgerContext::run($this->ctx, fn () => app(TrialBalance::class)->asOf($this->ctx, $asOf));
    }

    private function balanceOf(array $tb, string $systemKey): int
    {
        $id = LedgerContext::run($this->ctx, fn () => app(Chart::class)->system($systemKey, $this->ctx)->id);
        $row = collect($tb['rows'])->first(fn ($r) => $r['account']->id === $id);

        return $row === null ? 0 : $row['debit_balance'] - $row['credit_balance'];
    }

    private function pnlTotal(array $tb): int
    {
        return collect($tb['rows'])->filter(fn ($r) => in_array($r['account']->class, ['income', 'expense'], true))->sum(fn ($r) => $r['debit_balance'] - $r['credit_balance']);
    }

    public function test_months_get_a_period_the_first_time_something_posts_and_year_end_is_the_month_before_the_start(): void
    {
        $periods = AccountingPeriod::withoutGlobalScopes()->orderBy('start_date')->get();

        $this->assertSame(['2025-04-01', '2025-06-01', '2026-03-01'], $periods->pluck('start_date')->map->toDateString()->all());
        $this->assertSame([2026, 2026, 2027], $periods->pluck('fiscal_year')->all(), 'a March start: April 2025 belongs to the year ending February 2026');

        LedgerContext::run($this->ctx, fn () => $this->periods->closeThrough($this->ctx, CarbonImmutable::parse('2026-02-28')));
        $this->assertTrue(AccountingPeriod::withoutGlobalScopes()->where('end_date', '2026-02-28')->firstOrFail()->is_year_end);
        $this->assertFalse(AccountingPeriod::withoutGlobalScopes()->where('end_date', '2025-06-30')->firstOrFail()->is_year_end);
    }

    public function test_closing_through_a_month_closes_every_month_in_order_and_locks_the_books(): void
    {
        $closed = $this->closeThrough('2025-06-30');

        $this->assertSame(3, $closed, 'April, May (no activity, created on demand) and June');
        $settings = LedgerSetting::withoutGlobalScopes()->first();
        $this->assertSame('2025-06-30', $settings->books_closed_through->toDateString());
        $this->assertSame(['closed', 'closed', 'closed'], AccountingPeriod::withoutGlobalScopes()->where('end_date', '<=', '2025-06-30')->orderBy('start_date')->pluck('status')->all());
        $this->assertTrue(LedgerContext::run($this->ctx, fn () => app(GlVerifier::class)->verify($this->ctx))['ok']);
    }

    public function test_a_month_cannot_be_closed_before_the_one_before_it(): void
    {
        $june = LedgerContext::run($this->ctx, fn () => AccountingPeriod::query()->forContext($this->ctx)->where('start_date', '2025-06-01')->firstOrFail());

        $this->expectException(AccountingException::class);
        $this->expectExceptionMessage('Close April 2025 first');
        LedgerContext::run($this->ctx, fn () => $this->periods->close($this->ctx, $june, $this->user));
    }

    public function test_the_year_end_close_moves_the_years_profit_to_retained_earnings(): void
    {
        $before = $this->trial('2026-02-28');
        $this->assertSame(-10_000 + 4_000, $this->pnlTotal($before), 'before closing the P&L accounts carry the year: a 6,000 net credit');

        $this->closeThrough('2026-02-28');

        $after = $this->trial('2026-02-28');
        $this->assertSame(0, $this->pnlTotal($after), 'every income and expense account is back to zero');
        $this->assertSame(-6_000, $this->balanceOf($after, 'retained_earnings'), '6,000 profit credited to retained earnings');
        $this->assertTrue($after['balanced']);
        $this->assertTrue($after['equation']['holds']);

        $period = AccountingPeriod::withoutGlobalScopes()->where('end_date', '2026-02-28')->firstOrFail();
        $entry = JournalEntry::withoutGlobalScopes()->findOrFail($period->closing_entry_id);
        $this->assertSame('period_close', $entry->source_type);
        $this->assertSame('2026-02-28', $entry->entry_date->toDateString());
    }

    public function test_profit_and_loss_for_a_closed_year_still_reads_the_year_not_zero(): void
    {
        $this->closeThrough('2026-02-28');

        $pnl = LedgerContext::run($this->ctx, fn () => app(GlQuery::class)->incomeExpense($this->ctx, '2025-03-01', '2026-02-28'));

        $this->assertSame(['income_cents' => 10_000, 'expense_cents' => 4_000, 'net_cents' => 6_000], $pnl, 'closing entries are excluded from the P&L of the year they close');
    }

    public function test_the_next_year_starts_from_zero_and_nothing_is_double_counted(): void
    {
        $this->closeThrough('2026-02-28');

        $next = LedgerContext::run($this->ctx, fn () => app(GlQuery::class)->incomeExpense($this->ctx, '2026-03-01', '2027-02-28'));
        $this->assertSame(2_500, $next['income_cents']);
        $this->assertSame(0, $next['expense_cents']);

        $tb = $this->trial();
        $this->assertSame(-2_500, $this->pnlTotal($tb));
        $this->assertTrue($tb['equation']['holds']);
    }

    public function test_closed_months_refuse_new_postings(): void
    {
        $this->closeThrough('2025-06-30');

        $this->assertSame(0, JournalEntry::withoutGlobalScopes()->where('status', 'posted')->where('entry_date', '>', '2099-01-01')->count());
        $this->expectException(PeriodClosed::class);
        LedgerContext::run($this->ctx, fn () => app(JournalPoster::class)->post(new PostingRequest(
            CarbonImmutable::parse('2025-06-30'), 'manual', 'late', 1, 'Too late', [
                new PostingLine('cash', debitCents: 1), new PostingLine('sales', creditCents: 1),
            ],
        )));
    }

    public function test_reopening_needs_a_reason_unwinds_the_year_end_entry_and_unlocks_the_month(): void
    {
        $this->closeThrough('2026-02-28');
        $period = AccountingPeriod::withoutGlobalScopes()->where('end_date', '2026-02-28')->firstOrFail();

        try {
            LedgerContext::run($this->ctx, fn () => $this->periods->reopen($this->ctx, $period, '  ', $this->user));
            $this->fail('a reason is required');
        } catch (AccountingException) {
        }

        LedgerContext::run($this->ctx, fn () => $this->periods->reopen($this->ctx, $period, 'Auditor found a missing invoice', $this->user));

        $period->refresh();
        $this->assertSame('open', $period->status);
        $this->assertNull($period->closing_entry_id);
        $this->assertSame('Auditor found a missing invoice', $period->reopen_reason);
        $this->assertNotNull($period->reopened_at);

        $settings = LedgerSetting::withoutGlobalScopes()->first();
        $this->assertSame('2026-01-31', $settings->books_closed_through->toDateString(), 'locked through the month before again');

        $tb = $this->trial('2026-02-28');
        $this->assertSame(-6_000, $this->pnlTotal($tb), 'the year-end entry was reversed: the P&L accounts carry the year again');
        $this->assertSame(0, $this->balanceOf($tb, 'retained_earnings'));
        $this->assertTrue(LedgerContext::run($this->ctx, fn () => app(GlVerifier::class)->verify($this->ctx))['ok']);
    }

    public function test_only_the_newest_closed_month_can_be_reopened(): void
    {
        $this->closeThrough('2025-06-30');
        $april = AccountingPeriod::withoutGlobalScopes()->where('start_date', '2025-04-01')->firstOrFail();

        $this->expectException(AccountingException::class);
        $this->expectExceptionMessage('newest first');
        LedgerContext::run($this->ctx, fn () => $this->periods->reopen($this->ctx, $april, 'why not', $this->user));
    }

    public function test_closing_and_reopening_are_written_to_the_audit_log(): void
    {
        $this->closeThrough('2025-04-30');
        $april = AccountingPeriod::withoutGlobalScopes()->where('start_date', '2025-04-01')->firstOrFail();
        LedgerContext::run($this->ctx, fn () => $this->periods->reopen($this->ctx, $april, 'Wrong supplier', $this->user));

        $actions = FinanceAuditLog::withoutGlobalScopes()->where('subject_type', 'period')->orderBy('id')->pluck('action')->all();
        $this->assertSame(['period_closed', 'period_reopened'], array_values(array_filter($actions, fn ($a) => str_starts_with($a, 'period_'))));
        $reopened = FinanceAuditLog::withoutGlobalScopes()->where('action', 'period_reopened')->firstOrFail();
        $this->assertSame('Wrong supplier', $reopened->changes['reason']['after']);
        $this->assertStringContainsString('Wrong supplier', $reopened->context);
    }

    public function test_the_chart_keeps_postings_alive_when_an_account_is_deactivated(): void
    {
        $groceries = LedgerContext::run($this->ctx, fn () => GlAccount::query()->forContext($this->ctx)->where('name', 'Rent')->firstOrFail());

        // Mapped to a category: refused until the mapping is moved.
        $this->expectException(AccountingException::class);
        LedgerContext::run($this->ctx, fn () => app(Chart::class)->deactivate($groceries));
    }

    public function test_a_person_whose_books_have_accounting_on_cannot_delete_the_account_and_a_company_with_journals_is_kept(): void
    {
        $this->actingAs($this->user)->withSession(['auth.password_confirmed_at' => time()]);
        $deleter = app(DeleteUser::class);

        try {
            $deleter->delete($this->user);
            $this->fail('expected the delete to be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('posted journal entries are kept', $e->errors()['password'][0]);
        }
        $this->assertNotNull(User::find($this->user->id));

        $company = $this->company($this->user, 'Books Ltd');
        $this->enableAccounting($this->companyContext($company), 'ZAR', ChartTemplates::COMPANY);
        $this->assertTrue($company->fresh()->hasLedgerData());
    }
}
