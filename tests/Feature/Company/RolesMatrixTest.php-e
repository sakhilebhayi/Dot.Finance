<?php

namespace Tests\Feature\Company;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\RecurringRule;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\TestCase;

/**
 * Who may do what in a company ledger, per role, per action, at both layers: the policy
 * (Gate) and the real HTTP route. A denied write is a 403 and leaves the database exactly
 * as it was; an allowed one actually happens.
 *
 *   owner / admin  everything
 *   editor         entries, transfers, imports, recurring rules, and creating/editing
 *                  accounts, categories and budgets; NOT deleting those, NOT opening balances,
 *                  NOT the audit log
 *   viewer         read everything (reports, forecast, reconciliation) and nothing else
 *   unknown role   treated as viewer
 */
class RolesMatrixTest extends TestCase
{
    use BuildsCompanyLedgers;
    use RefreshDatabase;

    private const OWNER = 'owner';

    private const ADMIN = 'admin';

    private const EDITOR = 'editor';

    private const VIEWER = 'viewer';

    private const UNKNOWN = 'mystery-role';

    private const ALL = [self::OWNER, self::ADMIN, self::EDITOR, self::VIEWER, self::UNKNOWN];

    private const MANAGERS = [self::OWNER, self::ADMIN];

    private const WRITERS = [self::OWNER, self::ADMIN, self::EDITOR];

    private const LEDGER_TABLES = ['accounts', 'categories', 'budgets', 'transactions', 'recurring_rules', 'finance_audit_log', 'ai_insights'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** A company with its own rows and one person at each role. */
    private function world(): array
    {
        $owner = $this->user();
        $company = $this->company($owner, 'Acme Mining');

        $account = $this->companyAccount($company, ['name' => 'Acme Operating', 'currency' => 'ZAR']);
        $second = $this->companyAccount($company, ['name' => 'Acme Savings', 'currency' => 'ZAR']);
        $category = $this->companyCategory($company, ['name' => 'Fuel', 'type' => 'expense']);
        $entry = $this->entryOn($account, ['category_id' => $category->id, 'description' => 'Diesel', 'type' => 'expense', 'date' => now()->toDateString()]);
        $budget = Budget::factory()->forCompany($company)->create(['category_id' => $category->id, 'currency' => 'ZAR', 'month' => 3, 'year' => 2030]);
        $rule = RecurringRule::factory()->create(['user_id' => null, 'team_id' => $company->id, 'account_id' => $account->id, 'category_id' => $category->id]);

        return [
            'company' => $company, 'account' => $account, 'second' => $second, 'category' => $category, 'entry' => $entry, 'budget' => $budget, 'rule' => $rule,
            self::OWNER => $owner,
            self::ADMIN => $this->memberOf($company, 'admin'),
            self::EDITOR => $this->memberOf($company, 'editor'),
            self::VIEWER => $this->memberOf($company, 'viewer'),
            self::UNKNOWN => $this->memberOf($company, 'mystery-role'),
        ];
    }

    private function fingerprint(): string
    {
        $out = [];
        foreach (self::LEDGER_TABLES as $table) {
            $out[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }

        return md5(json_encode($out));
    }

    /** @return array<string, array{0: string, 1: callable(array): string, 2: callable(array): array, 3: list<string>}> action => [method, url, body, allowed roles] */
    private function actions(): array
    {
        $day = now()->toDateString();

        return [
            // reads: every member
            'view dashboard' => ['get', fn ($w) => route('dashboard'), fn ($w) => [], self::ALL],
            'view accounts' => ['get', fn ($w) => route('accounts.index'), fn ($w) => [], self::ALL],
            'view transactions' => ['get', fn ($w) => route('transactions.index'), fn ($w) => [], self::ALL],
            'view categories' => ['get', fn ($w) => route('categories.index'), fn ($w) => [], self::ALL],
            'view budgets' => ['get', fn ($w) => route('budgets.index'), fn ($w) => [], self::ALL],
            'view recurring' => ['get', fn ($w) => route('recurring.index'), fn ($w) => [], self::ALL],
            'view cash flow report' => ['get', fn ($w) => route('reports.cash-flow'), fn ($w) => [], self::ALL],
            'view forecast' => ['get', fn ($w) => route('forecast.index'), fn ($w) => [], self::ALL],
            'view weekly summaries' => ['get', fn ($w) => route('insights.index'), fn ($w) => [], self::ALL],
            'view reconciliation' => ['get', fn ($w) => route('reconciliation.index'), fn ($w) => [], self::ALL],
            // the company audit log: owners and admins only
            'view audit log' => ['get', fn ($w) => route('audit.index'), fn ($w) => [], self::MANAGERS],

            // accounts
            'open the account form target' => ['get', fn ($w) => route('accounts.edit', $w['account']), fn ($w) => [], self::WRITERS],
            'create an account' => ['post', fn ($w) => route('accounts.store'), fn ($w) => ['name' => 'New account', 'type' => 'savings', 'currency' => 'ZAR', 'opening_balance' => '1.00'], self::WRITERS],
            'edit an account' => ['put', fn ($w) => route('accounts.update', $w['account']), fn ($w) => ['name' => 'Renamed', 'type' => 'checking', 'currency' => 'ZAR'], self::WRITERS],
            'adjust an opening balance' => ['post', fn ($w) => route('accounts.opening-balance', $w['account']), fn ($w) => ['opening_balance' => '5.00', 'opening_balance_date' => $day], self::MANAGERS],
            'open the delete-account page' => ['get', fn ($w) => route('accounts.confirm-delete', $w['account']), fn ($w) => [], self::MANAGERS],
            'delete an account' => ['delete', fn ($w) => route('accounts.destroy', $w['account']), fn ($w) => ['confirm' => '1'], self::MANAGERS],

            // categories
            'create a category' => ['post', fn ($w) => route('categories.store'), fn ($w) => ['name' => 'Tyres', 'type' => 'expense'], self::WRITERS],
            'open a category' => ['get', fn ($w) => route('categories.edit', $w['category']), fn ($w) => [], self::WRITERS],
            'edit a category' => ['put', fn ($w) => route('categories.update', $w['category']), fn ($w) => ['name' => 'Fuel & oil', 'type' => 'expense'], self::WRITERS],
            'delete a category' => ['delete', fn ($w) => route('categories.destroy', $w['category']), fn ($w) => [], self::MANAGERS],

            // budgets
            'create a budget' => ['post', fn ($w) => route('budgets.store'), fn ($w) => ['category_id' => $w['category']->id, 'amount' => '100.00', 'currency' => 'ZAR', 'month' => 4, 'year' => 2030], self::WRITERS],
            'open a budget' => ['get', fn ($w) => route('budgets.edit', $w['budget']), fn ($w) => [], self::WRITERS],
            'edit a budget' => ['put', fn ($w) => route('budgets.update', $w['budget']), fn ($w) => ['category_id' => $w['category']->id, 'amount' => '250.00', 'currency' => 'ZAR', 'month' => 3, 'year' => 2030], self::WRITERS],
            'delete a budget' => ['delete', fn ($w) => route('budgets.destroy', $w['budget']), fn ($w) => [], self::MANAGERS],

            // transactions
            'open the new-transaction form' => ['get', fn ($w) => route('transactions.create'), fn ($w) => [], self::WRITERS],
            'create a transaction' => ['post', fn ($w) => route('transactions.store'), fn ($w) => ['account_id' => $w['account']->id, 'description' => 'Tolls', 'amount' => '12.00', 'type' => 'expense', 'date' => $day], self::WRITERS],
            'open a transaction' => ['get', fn ($w) => route('transactions.edit', $w['entry']), fn ($w) => [], self::WRITERS],
            'edit a transaction' => ['put', fn ($w) => route('transactions.update', $w['entry']), fn ($w) => ['account_id' => $w['account']->id, 'description' => 'Diesel (edited)', 'amount' => '99.00', 'type' => 'expense', 'date' => $day], self::WRITERS],
            'delete a transaction' => ['delete', fn ($w) => route('transactions.destroy', $w['entry']), fn ($w) => [], self::WRITERS],

            // transfers
            'open the transfer form' => ['get', fn ($w) => route('transfers.create'), fn ($w) => [], self::WRITERS],
            'make a transfer' => ['post', fn ($w) => route('transfers.store'), fn ($w) => ['from_account_id' => $w['account']->id, 'to_account_id' => $w['second']->id, 'amount' => '10.00', 'date' => $day], self::WRITERS],

            // imports
            'open the import form' => ['get', fn ($w) => route('transactions.import.create'), fn ($w) => [], self::WRITERS],
            'upload an import' => ['post', fn ($w) => route('transactions.import.upload'), fn ($w) => ['account_id' => $w['account']->id, 'file' => UploadedFile::fake()->createWithContent('s.csv', "date,description,amount\n2026-08-01,Supplier,-1.00\n")], self::WRITERS],

            // recurring rules
            'open a recurring rule' => ['get', fn ($w) => route('recurring.edit', $w['rule']), fn ($w) => [], self::WRITERS],
            'edit a recurring rule' => ['put', fn ($w) => route('recurring.update', $w['rule']), fn ($w) => ['description' => 'Rent (edited)', 'amount' => '10.00', 'recurrence_type' => 'monthly', 'recurrence_interval' => 1], self::WRITERS],
            'pause a recurring rule' => ['post', fn ($w) => route('recurring.pause', $w['rule']), fn ($w) => [], self::WRITERS],
            'stop a recurring rule' => ['post', fn ($w) => route('recurring.stop', $w['rule']), fn ($w) => [], self::WRITERS],
        ];
    }

    public function test_every_role_can_and_cannot_do_exactly_what_the_matrix_says(): void
    {
        $checks = 0;

        foreach ($this->actions() as $action => [$method, $url, $body, $allowed]) {
            foreach (self::ALL as $role) {
                $world = $this->world();
                $this->inCompany($world[$role], $world['company']);

                $before = $this->fingerprint();
                $response = $this->{$method}($url($world), $body($world));
                $status = $response->getStatusCode();
                $label = "{$role} / {$action} -> {$status}";

                if (in_array($role, $allowed, true)) {
                    $this->assertTrue($status === 200 || $status === 302, "{$label}: should be allowed");
                    // an allowed write must be an accepted one, not a 302 back with validation errors
                    $this->assertFalse(session()->has('errors'), "{$label}: the request was allowed but rejected by validation");
                } else {
                    $this->assertSame(403, $status, "{$label}: should be forbidden");
                    $this->assertSame($before, $this->fingerprint(), "{$label}: a forbidden request changed the database");
                }
                $checks++;
            }
        }

        $this->assertGreaterThan(150, $checks);
    }

    public function test_allowed_writes_really_change_the_company_ledger(): void
    {
        foreach ([self::OWNER, self::ADMIN, self::EDITOR] as $role) {
            $w = $this->world();
            $this->inCompany($w[$role], $w['company']);

            $this->post(route('transactions.store'), ['account_id' => $w['account']->id, 'description' => 'Tolls', 'amount' => '12.00', 'type' => 'expense', 'date' => now()->toDateString()])->assertRedirect(route('transactions.index'));
            $this->assertSame(1, Transaction::where('description', 'Tolls')->count(), "{$role} could not record an entry");

            $this->put(route('transactions.update', $w['entry']), ['account_id' => $w['account']->id, 'description' => 'Edited', 'amount' => '1.00', 'type' => 'expense', 'date' => now()->toDateString()]);
            $this->assertSame('Edited', $w['entry']->fresh()->description, "{$role} could not edit an entry");

            $this->delete(route('transactions.destroy', $w['entry']));
            $this->assertNull(Transaction::find($w['entry']->id), "{$role} could not delete an entry");

            $this->put(route('categories.update', $w['category']), ['name' => 'Renamed', 'type' => 'expense']);
            $this->assertSame('Renamed', $w['category']->fresh()->name, "{$role} could not edit a category");
        }
    }

    public function test_an_editor_cannot_delete_structure_or_move_opening_balances(): void
    {
        $w = $this->world();
        $this->inCompany($w[self::EDITOR], $w['company']);

        $this->delete(route('accounts.destroy', $w['account']), ['confirm' => '1'])->assertForbidden();
        $this->delete(route('categories.destroy', $w['category']))->assertForbidden();
        $this->delete(route('budgets.destroy', $w['budget']))->assertForbidden();
        $this->post(route('accounts.opening-balance', $w['account']), ['opening_balance' => '9.00', 'opening_balance_date' => now()->toDateString()])->assertForbidden();

        $this->assertNotNull(Account::find($w['account']->id));
        $this->assertNotNull(Category::find($w['category']->id));
        $this->assertNotNull(Budget::find($w['budget']->id));
        $this->assertSame(0, Account::find($w['account']->id)->opening_balance_cents);
    }

    // ── policy layer ───────────────────────────────────────────────────────

    public function test_the_policies_say_the_same_thing_as_the_routes(): void
    {
        $rows = [
            'account' => [fn ($w) => $w['account'], ['view' => self::ALL, 'update' => self::WRITERS, 'delete' => self::MANAGERS, 'adjustOpeningBalance' => self::MANAGERS]],
            'category' => [fn ($w) => $w['category'], ['view' => self::ALL, 'update' => self::WRITERS, 'delete' => self::MANAGERS]],
            'budget' => [fn ($w) => $w['budget'], ['view' => self::ALL, 'update' => self::WRITERS, 'delete' => self::MANAGERS]],
            'transaction' => [fn ($w) => $w['entry'], ['view' => self::ALL, 'update' => self::WRITERS, 'delete' => self::WRITERS]],
            'rule' => [fn ($w) => $w['rule'], ['view' => self::ALL, 'update' => self::WRITERS, 'delete' => self::WRITERS]],
        ];

        $w = $this->world();
        $outsider = $this->user();

        foreach ($rows as $name => [$row, $abilities]) {
            foreach ($abilities as $ability => $allowed) {
                foreach (self::ALL as $role) {
                    $this->assertSame(in_array($role, $allowed, true), Gate::forUser($w[$role])->allows($ability, $row($w)), "{$role} / {$ability} {$name}");
                }
                $this->assertFalse(Gate::forUser($outsider)->allows($ability, $row($w)), "an outsider must never {$ability} a company {$name}");
            }
        }
    }

    public function test_the_context_wide_abilities_follow_the_role(): void
    {
        $expect = [
            'record-entries' => self::WRITERS,
            'view-audit' => self::MANAGERS,
        ];
        $w = $this->world();

        foreach ($expect as $ability => $allowed) {
            foreach (self::ALL as $role) {
                $this->inCompany($w[$role], $w['company']);
                $this->assertSame(in_array($role, $allowed, true), Gate::forUser($w[$role])->allows($ability), "{$role} / {$ability}");
            }
        }

        foreach ([Account::class => self::WRITERS, Category::class => self::WRITERS, Budget::class => self::WRITERS, Transaction::class => self::WRITERS] as $model => $allowed) {
            foreach (self::ALL as $role) {
                $this->inCompany($w[$role], $w['company']);
                $this->assertSame(in_array($role, $allowed, true), Gate::forUser($w[$role])->allows('create', $model), "{$role} / create ".class_basename($model));
            }
        }
    }

    public function test_a_person_has_every_ability_in_their_own_personal_ledger(): void
    {
        $user = $this->user();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $this->inPersonal($user);

        foreach (['view', 'update', 'delete', 'adjustOpeningBalance'] as $ability) {
            $this->assertTrue(Gate::forUser($user)->allows($ability, $account), $ability);
        }
        foreach (['record-entries', 'view-audit'] as $ability) {
            $this->assertTrue(Gate::forUser($user)->allows($ability), $ability);
        }
        $this->assertTrue(Gate::forUser($user)->allows('create', Account::class));
    }

    public function test_a_role_is_per_company_a_viewer_in_one_can_be_an_admin_in_another(): void
    {
        $owner = $this->user();
        $one = $this->company($owner, 'One Ltd');
        $two = $this->company($this->user(), 'Two Ltd');
        $person = $this->memberOf($one, 'viewer');
        $two->users()->attach($person->id, ['role' => 'admin']);
        $person = $person->fresh();

        $this->inCompany($person, $one)->post(route('categories.store'), ['name' => 'x', 'type' => 'expense'])->assertForbidden();
        $this->inCompany($person, $two)->post(route('categories.store'), ['name' => 'x', 'type' => 'expense'])->assertRedirect(route('categories.index'));
    }

    public function test_the_company_owner_is_a_manager_even_without_a_membership_row(): void
    {
        $w = $this->world();
        $this->assertFalse($w['company']->users()->where('users.id', $w[self::OWNER]->id)->exists(), 'precondition: the owner has no pivot row');

        $this->inCompany($w[self::OWNER], $w['company'])->get(route('audit.index'))->assertOk();
    }

    public function test_an_outsider_never_sees_a_company_even_by_naming_it(): void
    {
        $w = $this->world();
        $outsider = $this->user();
        Account::factory()->create(['user_id' => $outsider->id, 'name' => 'Outsider cheque']);

        $this->inCompany($outsider, $w['company'])->get(route('accounts.index'))->assertOk()->assertSee('Outsider cheque')->assertDontSee('Acme Operating');
        $this->get(route('accounts.index', ['context' => $w['company']->id]))->assertNotFound();
        $this->post(route('accounts.store'), ['name' => 'Backdoor', 'type' => 'checking', 'currency' => 'ZAR', 'opening_balance' => '1.00', 'context' => $w['company']->id])->assertRedirect();
        $this->assertSame(0, Account::withoutGlobalScopes()->where('team_id', $w['company']->id)->where('name', 'Backdoor')->count());
    }

    // ── what each role sees on the page ────────────────────────────────────

    public function test_a_viewer_sees_no_write_controls_anywhere(): void
    {
        $w = $this->world();
        $this->inCompany($w[self::VIEWER], $w['company']);

        $pages = [
            'accounts.index' => ['Add Account', route('accounts.edit', $w['account']), route('accounts.confirm-delete', $w['account'])],
            'categories.index' => ['Add Category', route('categories.edit', $w['category'])],
            'budgets.index' => ['Add Budget', route('budgets.edit', $w['budget'])],
            'transactions.index' => ['Add Transaction', 'Import CSV', route('transfers.create'), route('transactions.edit', $w['entry'])],
            'recurring.index' => [route('recurring.edit', $w['rule']), route('recurring.pause', $w['rule']), route('recurring.stop', $w['rule'])],
            'dashboard' => ['Add Transaction', route('transactions.create')],
        ];

        foreach ($pages as $route => $forbiddenStrings) {
            $html = $this->get(route($route))->assertOk()->getContent();
            foreach ($forbiddenStrings as $needle) {
                $this->assertStringNotContainsString($needle, $html, "{$route} shows '{$needle}' to a viewer");
            }
        }

        $this->get(route('transactions.index'))->assertSee('Diesel');
        $this->get(route('accounts.index'))->assertSee('Acme Operating')->assertSee('read-only', false);
    }

    public function test_an_editor_sees_write_controls_but_not_delete_audit_or_opening_balance_controls(): void
    {
        $w = $this->world();
        $this->inCompany($w[self::EDITOR], $w['company']);

        $accounts = $this->get(route('accounts.index'))->assertOk();
        $accounts->assertSee('Add Account')->assertSee(route('accounts.edit', $w['account']))->assertDontSee(route('accounts.confirm-delete', $w['account']));
        $this->get(route('accounts.edit', $w['account']))->assertOk()->assertDontSee(route('accounts.opening-balance', $w['account']));
        $this->get(route('categories.index'))->assertSee('Add Category')->assertSee(route('categories.edit', $w['category']))->assertDontSee('action="'.route('categories.destroy', $w['category']).'"', false);
        $this->get(route('budgets.index'))->assertSee('Add Budget')->assertDontSee('action="'.route('budgets.destroy', $w['budget']).'"', false);
        $this->get(route('transactions.index'))->assertSee('Add Transaction')->assertSee('Import CSV')->assertSee('action="'.route('transactions.destroy', $w['entry']).'"', false);
        $this->get(route('dashboard'))->assertDontSee(route('audit.index'))->assertDontSee('Audit log');
    }

    public function test_an_admin_sees_every_control(): void
    {
        $w = $this->world();
        $this->inCompany($w[self::ADMIN], $w['company']);

        $this->get(route('accounts.index'))->assertSee(route('accounts.confirm-delete', $w['account']))->assertSee('Add Account');
        $this->get(route('accounts.edit', $w['account']))->assertSee(route('accounts.opening-balance', $w['account']));
        $this->get(route('categories.index'))->assertSee('action="'.route('categories.destroy', $w['category']).'"', false);
        $this->get(route('budgets.index'))->assertSee('action="'.route('budgets.destroy', $w['budget']).'"', false);
        $this->get(route('dashboard'))->assertSee(route('audit.index'));
    }

    public function test_the_shell_names_the_role_in_the_company(): void
    {
        $w = $this->world();

        foreach ([self::OWNER => 'Owner', self::ADMIN => 'Admin', self::EDITOR => 'Editor', self::VIEWER => 'Viewer', self::UNKNOWN => 'Viewer'] as $role => $label) {
            $this->inCompany($w[$role], $w['company'])->get(route('dashboard'))->assertSee("Your role: {$label}", false);
        }
    }

    public function test_the_audit_link_is_hidden_from_roles_that_cannot_open_it(): void
    {
        $w = $this->world();

        foreach ([self::EDITOR, self::VIEWER] as $role) {
            $this->inCompany($w[$role], $w['company'])->get(route('dashboard'))->assertDontSee(route('audit.index'));
        }
        $this->inPersonal($w[self::VIEWER])->get(route('dashboard'))->assertSee(route('audit.index'), false);
    }

    public function test_the_company_in_this_world_is_a_real_company(): void
    {
        $w = $this->world();
        $this->assertInstanceOf(Team::class, $w['company']);
        $this->assertTrue($w['company']->isCompany());
        $this->assertInstanceOf(User::class, $w[self::EDITOR]);
    }
}
