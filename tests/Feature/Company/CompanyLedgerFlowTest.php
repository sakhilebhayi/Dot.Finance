<?php

namespace Tests\Feature\Company;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Insight;
use App\Models\RecurringRule;
use App\Models\Transaction;
use App\Services\TransactionRecurrenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\TestCase;

/**
 * A company ledger works end to end for the company's owner: every screen reads
 * and every write lands in the company, never in the owner's personal ledger.
 */
class CompanyLedgerFlowTest extends TestCase
{
    use BuildsCompanyLedgers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_account_created_in_a_company_belongs_to_the_company_and_not_to_the_person(): void
    {
        $owner = $this->user();
        $company = $this->company($owner);

        $this->inCompany($owner, $company)->post(route('accounts.store'), [
            'name' => 'Acme Operating', 'type' => 'checking', 'currency' => 'zar', 'opening_balance' => '10000.00', 'opening_balance_date' => '2026-01-01',
        ])->assertRedirect(route('accounts.index'));

        $row = Account::withoutGlobalScopes()->where('name', 'Acme Operating')->firstOrFail();
        $this->assertSame($company->id, $row->team_id);
        $this->assertNull($row->user_id);
        $this->assertSame(1_000_000, $row->opening_balance_cents);

        $this->inPersonal($owner)->get(route('accounts.index'))->assertOk()->assertDontSee('Acme Operating');
    }

    public function test_an_account_created_in_the_personal_ledger_is_unchanged_by_this_feature(): void
    {
        $user = $this->user();

        $this->inPersonal($user)->post(route('accounts.store'), [
            'name' => 'Mine', 'type' => 'savings', 'currency' => 'USD', 'opening_balance' => '5.00',
        ])->assertRedirect(route('accounts.index'));

        $row = Account::withoutGlobalScopes()->where('name', 'Mine')->firstOrFail();
        $this->assertSame($user->id, $row->user_id);
        $this->assertNull($row->team_id);
    }

    public function test_the_whole_company_workflow_stays_inside_the_company(): void
    {
        $owner = $this->user();
        $company = $this->company($owner);
        $account = $this->companyAccount($company, ['name' => 'Acme Operating', 'currency' => 'ZAR']);
        $savings = $this->companyAccount($company, ['name' => 'Acme Savings', 'currency' => 'ZAR']);

        $this->inCompany($owner, $company);

        $this->post(route('categories.store'), ['name' => 'Hosting', 'type' => 'expense', 'color' => '#112233'])->assertRedirect(route('categories.index'));
        $category = Category::where('name', 'Hosting')->firstOrFail();
        $this->assertSame($company->id, $category->team_id);

        $this->post(route('transactions.store'), [
            'account_id' => $account->id, 'category_id' => $category->id, 'description' => 'Server', 'amount' => '250.00', 'type' => 'expense', 'date' => now()->toDateString(),
        ])->assertRedirect(route('transactions.index'));
        $tx = Transaction::where('description', 'Server')->firstOrFail();
        $this->assertSame($company->id, $tx->team_id);
        $this->assertNull($tx->user_id);

        $this->post(route('budgets.store'), [
            'category_id' => $category->id, 'amount' => '1000.00', 'currency' => 'ZAR', 'month' => now()->month, 'year' => now()->year,
        ])->assertRedirect(route('budgets.index'));
        $this->assertSame($company->id, Budget::firstOrFail()->team_id);

        $this->post(route('transfers.store'), [
            'from_account_id' => $account->id, 'to_account_id' => $savings->id, 'amount' => '100.00', 'date' => now()->toDateString(),
        ])->assertRedirect(route('transactions.index'));
        $this->assertSame(2, Transaction::where('type', 'transfer')->where('team_id', $company->id)->count());

        $this->post(route('transactions.store'), [
            'account_id' => $account->id, 'description' => 'Retainer', 'amount' => '75.00', 'type' => 'income', 'date' => now()->toDateString(),
            'recurrence_type' => 'monthly', 'recurrence_interval' => 1,
        ])->assertRedirect();
        $this->assertSame($company->id, RecurringRule::firstOrFail()->team_id);

        foreach (['dashboard', 'transactions.index', 'accounts.index', 'categories.index', 'budgets.index', 'recurring.index', 'reports.cash-flow', 'forecast.index', 'insights.index', 'audit.index', 'reconciliation.index', 'transfers.create', 'transactions.create'] as $name) {
            $this->get(route($name))->assertOk();
        }

        $this->assertSame(0, Account::withoutGlobalScopes()->whereNotNull('user_id')->count(), 'nothing was written to a personal ledger');
        $this->assertSame(0, Transaction::withoutGlobalScopes()->whereNotNull('user_id')->count());
        $this->inPersonal($owner)->get(route('transactions.index'))->assertOk()->assertDontSee('Server')->assertDontSee('Retainer');
    }

    public function test_the_dashboard_totals_a_company_per_currency_and_never_adds_in_personal_money(): void
    {
        $owner = $this->user();
        $company = $this->company($owner);
        $shared = $this->companyAccount($company, ['currency' => 'ZAR', 'opening_balance_cents' => 1_000_000, 'opening_balance_date' => '2026-01-01']);
        $this->companyAccount($company, ['currency' => 'USD', 'opening_balance_cents' => 222_200, 'opening_balance_date' => '2026-01-01']);
        $this->entryOn($shared, ['type' => 'income', 'amount_cents' => 50_000, 'date' => now()->toDateString()]);
        Account::factory()->create(['user_id' => $owner->id, 'currency' => 'ZAR', 'opening_balance_cents' => 999_999_900, 'opening_balance_date' => '2026-01-01']);

        $position = $this->inCompany($owner, $company)->get(route('dashboard'))->assertOk()->viewData('position');

        $this->assertSame(['USD', 'ZAR'], array_keys($position));
        $this->assertSame(1_050_000, $position['ZAR']['balance_cents']);
        $this->assertSame(222_200, $position['USD']['balance_cents']);
    }

    public function test_a_company_with_no_accounts_shows_a_clear_empty_state(): void
    {
        $owner = $this->user();
        $company = $this->company($owner, 'Fresh Co');
        Account::factory()->create(['user_id' => $owner->id]);

        $this->inCompany($owner, $company)->get(route('dashboard'))->assertOk()
            ->assertSee('Fresh Co has no accounts yet')
            ->assertSee('Create the first company account')
            ->assertSee(route('accounts.index'));

        $this->get(route('accounts.index'))->assertOk()->assertSee('Create the first company account');
    }

    public function test_a_company_ledger_is_reconciled_on_its_own(): void
    {
        $owner = $this->user();
        $company = $this->company($owner);
        $account = $this->companyAccount($company, ['name' => 'Acme Operating', 'opening_balance_cents' => 10_000, 'opening_balance_date' => '2026-01-01']);
        $this->entryOn($account, ['type' => 'income', 'amount_cents' => 5_000, 'date' => '2026-02-01']);
        Account::factory()->create(['user_id' => $owner->id, 'name' => 'Personal Pot']);

        $this->inCompany($owner, $company)->get(route('reconciliation.index'))->assertOk()
            ->assertSee('Acme Operating')->assertDontSee('Personal Pot');
    }

    public function test_the_recurring_runner_writes_each_rule_into_its_own_ledger(): void
    {
        Carbon::setTestNow('2026-09-15');
        $user = $this->user();
        $company = $this->company($this->user());
        $mine = RecurringRule::factory()->for($user)->create(['next_occurrence_date' => '2026-09-01', 'start_date' => '2026-09-01']);
        $theirs = RecurringRule::factory()->forCompany($company)->create(['next_occurrence_date' => '2026-09-01', 'start_date' => '2026-09-01']);

        $created = app(TransactionRecurrenceService::class)->generateDueOccurrences();

        $this->assertSame(2, $created);
        $personalEntry = Transaction::withoutGlobalScopes()->where('recurring_rule_id', $mine->id)->firstOrFail();
        $companyEntry = Transaction::withoutGlobalScopes()->where('recurring_rule_id', $theirs->id)->firstOrFail();
        $this->assertSame([$user->id, null], [$personalEntry->user_id, $personalEntry->team_id]);
        $this->assertSame([null, $company->id], [$companyEntry->user_id, $companyEntry->team_id]);
    }

    public function test_a_csv_import_in_a_company_lands_in_the_company_and_dedupes_there(): void
    {
        $owner = $this->user();
        $company = $this->company($owner);
        $account = $this->companyAccount($company, ['name' => 'Acme Operating']);
        $csv = "date,description,amount\n2026-08-01,Supplier invoice,-45.50\n2026-08-02,Customer payment,1500.00\n";

        $this->inCompany($owner, $company);

        $upload = fn () => $this->post(route('transactions.import.upload'), [
            'account_id' => $account->id, 'file' => UploadedFile::fake()->createWithContent('s.csv', $csv),
        ]);
        $token = basename(parse_url($upload()->assertSessionHasNoErrors()->headers->get('Location'), PHP_URL_PATH));
        $this->post(route('transactions.import.store'), ['token' => $token, 'date_format' => 'ymd', 'amount_locale' => 'en'])->assertRedirect(route('transactions.index'));

        $this->assertSame(2, Transaction::withoutGlobalScopes()->where('team_id', $company->id)->count());

        $again = basename(parse_url($upload()->headers->get('Location'), PHP_URL_PATH));
        $this->post(route('transactions.import.store'), ['token' => $again, 'date_format' => 'ymd', 'amount_locale' => 'en']);
        $this->assertSame(2, Transaction::withoutGlobalScopes()->where('team_id', $company->id)->count(), 'a second import of the same statement adds nothing');
    }

    public function test_an_import_staged_in_one_context_cannot_be_used_from_another(): void
    {
        $owner = $this->user();
        $company = $this->company($owner);
        $account = $this->companyAccount($company);
        $csv = "date,description,amount\n2026-08-01,Supplier invoice,-45.50\n";

        $this->inCompany($owner, $company);
        $token = basename(parse_url($this->post(route('transactions.import.upload'), [
            'account_id' => $account->id, 'file' => UploadedFile::fake()->createWithContent('s.csv', $csv),
        ])->headers->get('Location'), PHP_URL_PATH));

        $this->inPersonal($owner)->get(route('transactions.import.preview', ['token' => $token]))->assertRedirect(route('transactions.import.create'));
        $this->post(route('transactions.import.store'), ['token' => $token, 'date_format' => 'ymd', 'amount_locale' => 'en'])->assertSessionHasErrors('token');
        $this->assertSame(0, Transaction::withoutGlobalScopes()->count());
    }

    public function test_the_weekly_summary_job_and_balance_check_cover_company_ledgers(): void
    {
        Carbon::setTestNow('2026-09-15');
        $company = $this->company($this->user());
        $account = $this->companyAccount($company, ['currency' => 'ZAR', 'opening_balance_cents' => 100_000, 'opening_balance_date' => '2026-01-01']);
        $this->entryOn($account, ['type' => 'expense', 'amount_cents' => 40_000, 'date' => '2026-09-10']);

        $this->artisan('finance:generate-insights')->assertExitCode(0);
        $this->artisan('finance:check-balances')->assertExitCode(0);

        $insight = Insight::withoutGlobalScopes()->where('team_id', $company->id)->firstOrFail();
        $this->assertNull($insight->user_id);
        $this->assertSame('2026-W38', $insight->iso_week);
    }
}
