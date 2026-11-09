<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\GlBackfillRun;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\LedgerSetting;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\ChartTemplates;
use App\Services\Accounting\Exceptions\AccountingException;
use App\Services\Accounting\GlBackfill;
use App\Services\Accounting\GlQuery;
use App\Services\Accounting\GlVerifier;
use App\Services\TransferService;
use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsAccounting;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\Concerns\LiftsJournalGuards;
use Tests\TestCase;

class BackfillTest extends TestCase
{
    use BuildsAccounting;
    use BuildsCompanyLedgers;
    use LiftsJournalGuards;
    use RefreshDatabase;

    private User $user;

    private LedgerContext $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->user();
        $this->ctx = $this->personalContext($this->user);
    }

    /** A realistic personal ledger with the awkward cases the backfill has to name or handle. */
    private function history(): array
    {
        $cheque = Account::factory()->create(['user_id' => $this->user->id, 'name' => 'Cheque', 'opening_balance_cents' => 120_000, 'opening_balance_date' => '2026-01-01']);
        $savings = Account::factory()->create(['user_id' => $this->user->id, 'name' => 'Savings', 'type' => 'savings', 'opening_balance_cents' => 30_000, 'opening_balance_date' => '2026-01-01']);
        $card = Account::factory()->create(['user_id' => $this->user->id, 'name' => 'Card', 'type' => 'credit', 'opening_balance_cents' => -15_000, 'opening_balance_date' => '2026-01-01']);
        $dollars = Account::factory()->create(['user_id' => $this->user->id, 'name' => 'Dollars', 'currency' => 'USD', 'opening_balance_cents' => 0, 'opening_balance_date' => '2026-01-01']);
        $undated = Account::factory()->create(['user_id' => $this->user->id, 'name' => 'Undated', 'opening_balance_cents' => 1_000, 'opening_balance_date' => null]);

        $groceries = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Groceries', 'type' => 'expense']);
        $salary = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Salary', 'type' => 'income']);

        Transaction::factory()->create(['account_id' => $cheque->id, 'category_id' => $salary->id, 'type' => 'income', 'amount_cents' => 500_000, 'date' => '2026-01-25']);
        Transaction::factory()->create(['account_id' => $cheque->id, 'category_id' => $groceries->id, 'type' => 'expense', 'amount_cents' => 83_450, 'date' => '2026-02-03']);
        Transaction::factory()->create(['account_id' => $card->id, 'category_id' => $groceries->id, 'type' => 'expense', 'amount_cents' => 12_000, 'date' => '2026-02-04']);
        Transaction::factory()->create(['account_id' => $cheque->id, 'type' => 'expense', 'amount_cents' => 999, 'date' => '2026-02-05']); // uncategorised
        Transaction::factory()->create(['account_id' => $undated->id, 'type' => 'expense', 'amount_cents' => 250, 'date' => '2019-05-05']); // no opening date: every row counts
        $gone = Transaction::factory()->create(['account_id' => $cheque->id, 'type' => 'expense', 'amount_cents' => 7_777, 'date' => '2026-02-06']);
        $gone->delete();
        $preOpening = Transaction::factory()->create(['account_id' => $cheque->id, 'type' => 'expense', 'amount_cents' => 4_000, 'date' => '2025-12-31']); // before the opening date
        Transaction::factory()->create(['account_id' => $cheque->id, 'type' => 'transfer', 'transfer_direction' => null, 'amount_cents' => 1_234, 'date' => '2026-02-07']); // legacy

        $transfers = app(TransferService::class);
        $transfers->create($this->user, $cheque, $savings, 20_000, null, Carbon::parse('2026-02-10'), 'Save', null);
        $transfers->create($this->user, $cheque, $dollars, 36_000, 2_000, Carbon::parse('2026-02-11'), 'Holiday dollars', null);
        $transfers->create($this->user, $card, $cheque, 5_000, null, Carbon::parse('2026-02-12'), 'Pay the card', null);

        return compact('cheque', 'savings', 'card', 'dollars', 'undated', 'preOpening');
    }

    private function counts(): array
    {
        return [
            'entries' => JournalEntry::withoutGlobalScopes()->count(),
            'lines' => DB::table('journal_lines')->count(),
            'hash' => DB::table('journal_entries')->orderBy('id')->pluck('entry_hash')->implode(','),
        ];
    }

    public function test_prepare_creates_settings_chart_and_a_gl_account_for_every_cash_account_and_category(): void
    {
        $this->history();

        $settings = LedgerContext::run($this->ctx, fn () => app(GlBackfill::class)->prepare($this->ctx, 'ZAR', 3, ChartTemplates::PERSONAL));

        $this->assertSame('ZAR', $settings->base_currency);
        $this->assertSame(3, $settings->fiscal_year_start_month);
        $this->assertFalse($settings->isEnabled());
        $this->assertSame(0, Account::query()->forContext($this->ctx)->whereNull('gl_account_id')->count());
        $this->assertSame(0, Category::query()->forContext($this->ctx)->whereNull('gl_account_id')->count());

        $card = Account::query()->forContext($this->ctx)->where('name', 'Card')->firstOrFail();
        $this->assertSame('liability', $card->glAccount?->class ?? GlAccount::query()->forContext($this->ctx)->find($card->gl_account_id)->class);
    }

    public function test_prepare_twice_changes_nothing(): void
    {
        $this->history();
        $backfill = app(GlBackfill::class);

        LedgerContext::run($this->ctx, fn () => $backfill->prepare($this->ctx, 'ZAR', 1, ChartTemplates::PERSONAL));
        $count = DB::table('gl_accounts')->count();
        LedgerContext::run($this->ctx, fn () => $backfill->prepare($this->ctx, 'ZAR', 1, ChartTemplates::PERSONAL));

        $this->assertSame($count, DB::table('gl_accounts')->count());
        $this->assertSame(1, LedgerSetting::withoutGlobalScopes()->count());
    }

    public function test_the_dry_run_reports_and_keeps_nothing(): void
    {
        $this->history();
        $backfill = app(GlBackfill::class);
        LedgerContext::run($this->ctx, fn () => $backfill->prepare($this->ctx, 'ZAR', 1, ChartTemplates::PERSONAL));
        $before = $this->counts();

        $run = $backfill->dryRun($this->ctx, $this->user);

        $this->assertSame('dry_run', $run->status);
        $this->assertGreaterThan(0, $run->entries_posted);
        $this->assertSame($before, $this->counts(), 'the dry run rolled everything back');
        $this->assertSame(0, JournalEntry::withoutGlobalScopes()->count());
        $this->assertNull(LedgerSetting::withoutGlobalScopes()->first()->accounting_enabled_at);

        $reasons = collect($run->exceptions)->pluck('reason')->implode(' | ');
        $this->assertStringContainsString('before the account', $reasons);
        $this->assertStringContainsString('Legacy transfer', $reasons);
        $this->assertTrue($run->report['verify']['ok'], json_encode(collect($run->report['verify']['checks'])->where('ok', false)->values()));
    }

    public function test_apply_posts_the_history_and_verifies_cash_ties_out_to_the_cent(): void
    {
        $accounts = $this->history();
        $backfill = app(GlBackfill::class);
        LedgerContext::run($this->ctx, fn () => $backfill->prepare($this->ctx, 'ZAR', 1, ChartTemplates::PERSONAL));

        $run = $backfill->apply($this->ctx, $this->user);

        $this->assertSame('verified', $run->status, json_encode(collect($run->report['verify']['checks'])->where('ok', false)->values()));

        $tie = collect(LedgerContext::run($this->ctx, fn () => app(GlQuery::class)->cashTieOut($this->ctx)))->keyBy('name');
        $this->assertSame(120_000 + 500_000 - 83_450 - 999 - 20_000 - 36_000 + 5_000, $tie['Cheque']['cash_cents']);
        foreach ($tie as $name => $row) {
            $this->assertTrue($row['ok'], "{$name}: cash {$row['cash_cents']} vs journals {$row['gl_cents']}");
        }
        $this->assertSame(2_000, $tie['Dollars']['gl_cents'], 'USD account ties out in USD through the stored original amount');

        // The pre-opening row and the legacy row are named, not posted.
        $reasons = collect($run->exceptions)->pluck('source')->implode(' ');
        $this->assertStringContainsString('transaction #'.$accounts['preOpening']->id, $reasons);
    }

    public function test_replaying_the_backfill_changes_nothing(): void
    {
        $this->history();
        $backfill = app(GlBackfill::class);
        LedgerContext::run($this->ctx, fn () => $backfill->prepare($this->ctx, 'ZAR', 1, ChartTemplates::PERSONAL));

        $backfill->apply($this->ctx, $this->user);
        $first = $this->counts();
        $again = $backfill->apply($this->ctx, $this->user);
        $backfill->apply($this->ctx, $this->user);

        $this->assertSame($first, $this->counts(), 'same entries, same lines, same hash chain after two more replays');
        $this->assertSame('verified', $again->status);
        $this->assertSame(0, $again->entries_posted);
    }

    public function test_an_interrupted_apply_resumes_from_its_cursor_and_ends_in_the_same_books(): void
    {
        $this->history();
        $backfill = app(GlBackfill::class);
        LedgerContext::run($this->ctx, fn () => $backfill->prepare($this->ctx, 'ZAR', 1, ChartTemplates::PERSONAL));

        // Reference: an uninterrupted run in a twin ledger would number differently, so compare to a straight run here.
        $complete = $backfill->apply($this->ctx, $this->user);
        $expected = $this->counts();
        $this->assertSame('verified', $complete->status);

        // Now wipe the journals the only way tests may (drop the immutability triggers inside this test's transaction)
        // and resume from a cursor in the middle of the transaction phase.
        $this->liftJournalGuards();
        DB::table('journal_lines')->delete();
        DB::table('journal_entries')->delete();
        DB::table('accounting_periods')->delete();

        $midpoint = (int) Transaction::query()->forContext($this->ctx)->withTrashed()->orderBy('id')->get()->get(5)->id;
        $run = GlBackfillRun::create($this->ctx->ownerAttributes() + ['mode' => 'apply', 'status' => 'running', 'started_at' => now(), 'cursor_phase' => 'transactions', 'cursor_id' => $midpoint]);

        // Resumed from the middle: the opening phase and the first rows are skipped...
        $backfill->apply($this->ctx, $this->user, $run);
        $resumed = DB::table('journal_entries')->count();
        $this->assertLessThan($expected['entries'], $resumed, 'work before the cursor was not redone');

        // ...and a second, full apply (cursor reset) completes the books to the same content.
        $backfill->apply($this->ctx, $this->user);
        $this->assertSame($expected['entries'], DB::table('journal_entries')->count());
        $this->assertTrue(LedgerContext::run($this->ctx, fn () => app(GlVerifier::class)->verify($this->ctx))['ok']);
    }

    public function test_accounting_cannot_be_enabled_before_a_clean_apply(): void
    {
        $this->history();
        $backfill = app(GlBackfill::class);
        LedgerContext::run($this->ctx, fn () => $backfill->prepare($this->ctx, 'ZAR', 1, ChartTemplates::PERSONAL));

        $this->expectException(AccountingException::class);
        LedgerContext::run($this->ctx, fn () => $backfill->enable($this->ctx));
    }

    public function test_enable_sets_the_flag_once_and_records_the_start_date(): void
    {
        $this->history();
        $settings = $this->enableAccounting($this->ctx);

        $this->assertNotNull($settings->accounting_enabled_at);
        $this->assertSame('2019-05-05', $settings->accounting_start_date->toDateString(), 'the earliest date any row counts from');
        $this->assertSame($settings->accounting_enabled_at->toDateTimeString(), LedgerContext::run($this->ctx, fn () => app(GlBackfill::class)->enable($this->ctx))->accounting_enabled_at->toDateTimeString(), 'enabling twice is harmless');
    }

    public function test_an_account_in_a_foreign_currency_with_no_rate_stops_the_apply_from_verifying(): void
    {
        $dollars = Account::factory()->create(['user_id' => $this->user->id, 'name' => 'Dollars', 'currency' => 'USD', 'opening_balance_cents' => 10_000, 'opening_balance_date' => '2026-01-01']);
        Transaction::factory()->create(['account_id' => $dollars->id, 'type' => 'expense', 'amount_cents' => 500, 'date' => '2026-02-01']);
        $backfill = app(GlBackfill::class);
        LedgerContext::run($this->ctx, fn () => $backfill->prepare($this->ctx, 'ZAR', 1, ChartTemplates::PERSONAL));

        $run = $backfill->apply($this->ctx, $this->user);

        $this->assertSame('applied', $run->status, 'posted what it could, but did not verify');
        $this->assertFalse($run->report['verify']['ok']);
        $this->assertStringContainsString('No USD to ZAR exchange rate', collect($run->exceptions)->pluck('reason')->implode(' '));
        $this->expectException(AccountingException::class);
        LedgerContext::run($this->ctx, fn () => $backfill->enable($this->ctx));
    }

    public function test_the_company_ledger_is_backfilled_with_its_own_numbering_and_never_touches_the_personal_one(): void
    {
        $this->history();
        $company = $this->company($this->user);
        $cctx = $this->companyContext($company);
        $acct = $this->companyAccount($company, ['opening_balance_cents' => 50_000, 'opening_balance_date' => '2026-01-01']);
        Transaction::factory()->create(['account_id' => $acct->id, 'type' => 'income', 'amount_cents' => 10_000, 'date' => '2026-02-01']);

        $this->enableAccounting($this->ctx);
        $personal = $this->counts();
        $this->enableAccounting($cctx, 'ZAR', ChartTemplates::COMPANY);

        $this->assertSame(2, JournalEntry::withoutGlobalScopes()->where('team_id', $company->id)->count());
        $this->assertSame([1, 2], JournalEntry::withoutGlobalScopes()->where('team_id', $company->id)->orderBy('entry_number')->pluck('entry_number')->all());
        $this->assertSame($personal['entries'], JournalEntry::withoutGlobalScopes()->where('user_id', $this->user->id)->count());
        $this->assertSame(0, JournalEntry::withoutGlobalScopes()->whereNotNull('team_id')->whereNotNull('user_id')->count());
    }
}
