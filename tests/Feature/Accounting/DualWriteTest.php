<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalLine;
use App\Models\Category;
use App\Models\RecurringRule;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\GlQuery;
use App\Services\Accounting\GlVerifier;
use App\Services\Accounting\Periods;
use App\Services\TransactionRecurrenceService;
use App\Services\TransferService;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsAccounting;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\TestCase;

/** With accounting on, every way a cash-ledger row is written also journals it, inside the same transaction. */
class DualWriteTest extends TestCase
{
    use BuildsAccounting;
    use BuildsCompanyLedgers;
    use RefreshDatabase;

    private User $user;

    private LedgerContext $ctx;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->user = $this->user();
        $this->ctx = $this->personalContext($this->user);
        $this->bank = Account::factory()->create(['user_id' => $this->user->id, 'name' => 'Cheque', 'opening_balance_cents' => 100_000, 'opening_balance_date' => '2026-01-01']);
        $this->enableAccounting($this->ctx);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function verify(): array
    {
        return LedgerContext::run($this->ctx, fn () => app(GlVerifier::class)->verify($this->ctx));
    }

    private function assertBooksTie(): void
    {
        $report = $this->verify();
        $this->assertTrue($report['ok'], json_encode(collect($report['checks'])->where('ok', false)->values()));
    }

    /** @return Collection<int, JournalEntry> */
    private function entries(?string $type = null)
    {
        return LedgerContext::run($this->ctx, fn () => JournalEntry::query()->forContext($this->ctx)->when($type, fn ($q) => $q->where('source_type', $type))->orderBy('entry_number')->with('lines')->get());
    }

    private function store(array $overrides = [])
    {
        return $this->inPersonal($this->user)->post(route('transactions.store'), array_merge([
            'account_id' => $this->bank->id, 'description' => 'Fuel', 'amount' => '250.00', 'type' => 'expense', 'date' => '2026-03-05',
        ], $overrides));
    }

    public function test_creating_a_transaction_posts_a_balanced_entry_in_the_same_request(): void
    {
        $category = Category::factory()->create(['user_id' => $this->user->id, 'type' => 'expense']);

        $this->store(['category_id' => $category->id])->assertRedirect(route('transactions.index'));

        $entry = $this->entries('transaction')->first();
        $this->assertSame(25_000, $entry->total_cents);
        $this->assertSame(25_000, (int) $entry->lines->sum('debit_cents'));
        $this->assertSame(25_000, (int) $entry->lines->sum('credit_cents'));
        $this->assertSame($this->bank->id, $entry->lines->firstWhere('credit_cents', '>', 0)->cash_account_id);
        $this->assertSame($category->id, $entry->lines->firstWhere('debit_cents', '>', 0)->category_id);
        $this->assertBooksTie();
    }

    public function test_the_cash_ledger_reads_are_unchanged_by_accounting(): void
    {
        $this->store();

        $this->assertSame(100_000 - 25_000, Account::withBalance()->forContext($this->ctx)->findOrFail($this->bank->id)->balanceCents());
        $this->inPersonal($this->user)->get(route('transactions.index'))->assertOk()->assertSee('Fuel');
    }

    public function test_nothing_is_posted_while_accounting_is_off(): void
    {
        $stranger = $this->user();
        $account = Account::factory()->create(['user_id' => $stranger->id]);

        $this->inPersonal($stranger)->post(route('transactions.store'), ['account_id' => $account->id, 'description' => 'Coffee', 'amount' => '10.00', 'type' => 'expense', 'date' => '2026-03-05'])->assertRedirect();

        $this->assertSame(0, JournalEntry::withoutGlobalScopes()->where('user_id', $stranger->id)->count());
    }

    public function test_editing_reverses_and_reposts_and_the_books_tie_out(): void
    {
        $this->store();
        $tx = Transaction::query()->forContext($this->ctx)->where('description', 'Fuel')->firstOrFail();

        $this->inPersonal($this->user)->put(route('transactions.update', $tx), [
            'account_id' => $this->bank->id, 'description' => 'Fuel again', 'amount' => '350.00', 'type' => 'expense', 'date' => '2026-03-06', 'version' => $tx->version,
        ])->assertRedirect(route('transactions.index'));

        $versions = $this->entries('transaction')->pluck('status', 'source_version')->all();
        $this->assertSame([1 => 'reversed', 2 => 'posted'], $versions);
        $this->assertSame(1, $this->entries('reversal')->count());
        $this->assertSame(35_000, $this->entries('transaction')->last()->total_cents);
        $this->assertBooksTie();
    }

    public function test_editing_only_the_description_does_not_repost(): void
    {
        $this->store();
        $tx = Transaction::query()->forContext($this->ctx)->where('description', 'Fuel')->firstOrFail();
        $before = $this->entries()->count();

        $this->inPersonal($this->user)->put(route('transactions.update', $tx), [
            'account_id' => $this->bank->id, 'description' => 'Petrol', 'amount' => '250.00', 'type' => 'expense', 'date' => '2026-03-05', 'version' => $tx->version,
        ])->assertRedirect();

        $this->assertSame($before, $this->entries()->count(), 'a words-only edit changes no money, so no new entry');
    }

    public function test_deleting_posts_a_reversal_and_the_books_tie_out(): void
    {
        $this->store();
        $tx = Transaction::query()->forContext($this->ctx)->where('description', 'Fuel')->firstOrFail();

        $this->inPersonal($this->user)->delete(route('transactions.destroy', $tx))->assertRedirect();

        $this->assertSame('reversed', $this->entries('transaction')->first()->status);
        $this->assertSame(1, $this->entries('reversal')->count());
        $this->assertBooksTie();
        $this->assertSame(100_000, LedgerContext::run($this->ctx, fn () => app(GlQuery::class)->cashTieOut($this->ctx))[0]['gl_cents']);
    }

    public function test_a_transfer_is_one_entry_and_deleting_either_leg_reverses_it(): void
    {
        $savings = Account::factory()->create(['user_id' => $this->user->id, 'name' => 'Savings', 'type' => 'savings', 'opening_balance_cents' => 0, 'opening_balance_date' => '2026-01-01']);
        LedgerContext::run($this->ctx, fn () => app(TransferService::class)->create($this->user, $this->bank, $savings, 40_000, null, Carbon::parse('2026-03-10'), 'Move', null));

        $this->assertSame(1, $this->entries('transfer')->count());
        $this->assertBooksTie();

        $leg = Transaction::query()->forContext($this->ctx)->where('transfer_direction', 'in')->firstOrFail();
        $this->inPersonal($this->user)->delete(route('transactions.destroy', $leg))->assertRedirect();

        $this->assertSame('reversed', $this->entries('transfer')->first()->status);
        $this->assertBooksTie();
    }

    public function test_a_cross_currency_transfer_stores_the_implied_rate_and_ties_out_in_each_currency(): void
    {
        $usd = Account::factory()->create(['user_id' => $this->user->id, 'name' => 'Dollars', 'currency' => 'USD', 'opening_balance_cents' => 0, 'opening_balance_date' => '2026-01-01']);
        LedgerContext::run($this->ctx, fn () => app(TransferService::class)->create($this->user, $this->bank, $usd, 100_000, 5_000, Carbon::parse('2026-03-10'), 'To dollars', null));

        $line = $this->entries('transfer')->first()->lines->firstWhere('txn_currency', 'USD');
        $this->assertSame(100_000, $line->debit_cents);
        $this->assertSame(5_000, $line->txn_amount_cents);
        $this->assertSame(2_000_000_000, $line->fx_rate_e8);

        $tie = collect(LedgerContext::run($this->ctx, fn () => app(GlQuery::class)->cashTieOut($this->ctx)))->keyBy('name');
        $this->assertSame(5_000, $tie['Dollars']['gl_cents'], 'the USD account ties out in USD');
        $this->assertSame(5_000, $tie['Dollars']['cash_cents']);
        $this->assertBooksTie();
    }

    public function test_a_foreign_currency_row_without_a_rate_is_deferred_not_blocking(): void
    {
        $usd = Account::factory()->create(['user_id' => $this->user->id, 'name' => 'Dollars', 'currency' => 'USD', 'opening_balance_cents' => 0, 'opening_balance_date' => '2026-01-01']);

        $this->store(['account_id' => $usd->id, 'description' => 'Hosting'])->assertRedirect(route('transactions.index'));

        $this->assertSame(1, Transaction::query()->forContext($this->ctx)->where('description', 'Hosting')->count(), 'the person can still record their money');
        $this->assertSame(0, $this->entries('transaction')->count(), 'but there is no exchange rate, so nothing is posted');

        $report = $this->verify();
        $this->assertFalse($report['ok']);
        $this->assertContains('pending_sources', collect($report['checks'])->where('ok', false)->pluck('code')->all());
    }

    public function test_an_import_journals_every_row_in_the_import_transaction(): void
    {
        $csv = "date,description,amount\n2026-04-01,Salary,5000.00\n2026-04-02,Rent,-1200.00\n";
        $response = $this->inPersonal($this->user)->post(route('transactions.import.upload'), ['account_id' => $this->bank->id, 'file' => UploadedFile::fake()->createWithContent('s.csv', $csv)]);
        $token = basename(parse_url($response->headers->get('Location'), PHP_URL_PATH));

        $this->inPersonal($this->user)->post(route('transactions.import.store'), ['token' => $token, 'date_format' => 'ymd', 'amount_locale' => 'en'])->assertRedirect(route('transactions.index'));

        $this->assertSame(2, $this->entries('transaction')->count());
        $this->assertBooksTie();
    }

    public function test_a_recurring_occurrence_is_journalled_and_two_stale_workers_cannot_double_it(): void
    {
        Carbon::setTestNow('2026-06-15');
        $rule = RecurringRule::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->bank->id, 'amount_cents' => 9_900, 'type' => 'expense', 'start_date' => '2026-05-15', 'next_occurrence_date' => '2026-05-15', 'recurrence_type' => 'monthly', 'recurrence_interval' => 1]);

        // Two workers that loaded the rule before either ran.
        $worker1 = RecurringRule::query()->forContext($this->ctx)->findOrFail($rule->id);
        $worker2 = RecurringRule::query()->forContext($this->ctx)->findOrFail($rule->id);
        $service = app(TransactionRecurrenceService::class);

        $first = LedgerContext::run($this->ctx, fn () => $service->catchUp($worker1));
        $second = LedgerContext::run($this->ctx, fn () => $service->catchUp($worker2));

        $this->assertSame(2, $first);
        $this->assertSame(0, $second, 'the late worker sees the advanced date under the lock and has nothing to do');
        $this->assertSame(2, Transaction::query()->forContext($this->ctx)->where('recurring_rule_id', $rule->id)->count());
        $this->assertSame(2, $this->entries('transaction')->count());
        $this->assertBooksTie();
    }

    public function test_a_closed_period_refuses_a_new_edit_and_delete_and_writes_nothing(): void
    {
        $this->store(['date' => '2026-02-10', 'description' => 'February fuel'])->assertRedirect();
        $tx = Transaction::query()->forContext($this->ctx)->where('description', 'February fuel')->firstOrFail();

        LedgerContext::run($this->ctx, fn () => app(Periods::class)->closeThrough($this->ctx, CarbonImmutable::parse('2026-02-28'), $this->user));

        $before = [Transaction::query()->forContext($this->ctx)->count(), $this->entries()->count()];

        $this->store(['date' => '2026-02-20', 'description' => 'Backdated'])->assertSessionHasErrors('date');
        $this->inPersonal($this->user)->put(route('transactions.update', $tx), ['account_id' => $this->bank->id, 'description' => 'February fuel', 'amount' => '1.00', 'type' => 'expense', 'date' => '2026-02-10', 'version' => $tx->version])->assertSessionHasErrors('date');
        $this->inPersonal($this->user)->delete(route('transactions.destroy', $tx))->assertSessionHasErrors('transaction');

        $this->assertSame($before, [Transaction::query()->forContext($this->ctx)->count(), $this->entries()->count()]);
        $this->assertSame(25_000, Transaction::query()->forContext($this->ctx)->findOrFail($tx->id)->amount_cents, 'the closed month is untouched');
        $this->store(['date' => '2026-03-02', 'description' => 'March'])->assertSessionHasNoErrors();
        $this->assertBooksTie();
    }

    public function test_moving_an_opening_balance_reposts_it_and_re_decides_which_rows_count(): void
    {
        $this->store(['date' => '2026-01-20', 'description' => 'January fuel']); // counted: opening is 2026-01-01
        $this->assertSame(1, $this->entries('transaction')->count());

        $this->inPersonal($this->user)->post(route('accounts.opening-balance', $this->bank), [
            'opening_balance' => '800.00', 'opening_balance_date' => '2026-02-01', 'reason' => 'statement',
        ])->assertRedirect();

        // The January row now predates the opening date: it leaves the balance, and so leaves the books.
        $this->assertSame('reversed', $this->entries('transaction')->first()->status);
        $this->assertSame(80_000, Account::withBalance()->forContext($this->ctx)->findOrFail($this->bank->id)->balanceCents());
        $this->assertBooksTie();
    }

    public function test_deleting_an_account_with_a_balance_is_refused_and_an_empty_one_reverses_its_history(): void
    {
        $this->inPersonal($this->user)->delete(route('accounts.destroy', $this->bank), ['confirm' => 1])->assertSessionHasErrors('confirm');
        $this->assertNull(Account::withTrashed()->findOrFail($this->bank->id)->deleted_at);

        $this->inPersonal($this->user)->post(route('accounts.store'), ['name' => 'Empty', 'type' => 'checking', 'currency' => 'ZAR', 'opening_balance' => '50.00', 'opening_balance_date' => '2026-01-01'])->assertRedirect(route('accounts.index'));
        $empty = Account::query()->forContext($this->ctx)->where('name', 'Empty')->firstOrFail();
        $this->assertBooksTie();
        $this->store(['account_id' => $empty->id, 'amount' => '50.00', 'description' => 'Spend it all']);
        $this->assertBooksTie();

        $this->inPersonal($this->user)->delete(route('accounts.destroy', $empty), ['confirm' => 1])->assertRedirect();

        $this->assertNotNull(Account::withTrashed()->findOrFail($empty->id)->deleted_at);
        $net = LedgerContext::run($this->ctx, fn () => JournalLine::query()->forContext($this->ctx)->where('cash_account_id', $empty->id)->get()->sum(fn ($l) => $l->debit_cents - $l->credit_cents));
        $this->assertSame(0, $net, 'everything the deleted account posted is reversed');
        $this->assertBooksTie();
    }

    public function test_a_stale_edit_form_is_refused_with_a_clear_message(): void
    {
        $this->store();
        $tx = Transaction::query()->forContext($this->ctx)->where('description', 'Fuel')->firstOrFail();
        $payload = ['account_id' => $this->bank->id, 'description' => 'Fuel', 'amount' => '260.00', 'type' => 'expense', 'date' => '2026-03-05'];

        $this->inPersonal($this->user)->put(route('transactions.update', $tx), $payload + ['version' => $tx->version])->assertRedirect(route('transactions.index'));
        $this->inPersonal($this->user)->put(route('transactions.update', $tx), ['amount' => '999.00'] + $payload + ['version' => $tx->version])->assertSessionHasErrors('version');

        $this->assertSame(26_000, Transaction::query()->forContext($this->ctx)->findOrFail($tx->id)->amount_cents);
    }
}
