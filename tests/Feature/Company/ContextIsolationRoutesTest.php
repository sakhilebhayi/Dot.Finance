<?php

namespace Tests\Feature\Company;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Insight;
use App\Models\RecurringRule;
use App\Models\Team;
use App\Models\User;
use App\Services\TransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\TestCase;

/**
 * The regression net for the whole feature. It walks the application's own route
 * list, so a route added later without a matching entry here fails the suite,
 * and it smuggles a row id from a context the caller is NOT in into every route
 * parameter. Each attempt must be a 404 and must leave the database byte-for-byte
 * as it was.
 *
 * Callers are tried in the personal ledger, in a company they belong to, and in a
 * company they do not belong to at all; the foreign rows come from the personal
 * ledger, from each company and from another person.
 */
class ContextIsolationRoutesTest extends TestCase
{
    use BuildsCompanyLedgers;
    use RefreshDatabase;

    private const LEDGER_TABLES = ['accounts', 'categories', 'budgets', 'transactions', 'recurring_rules', 'finance_audit_log', 'ai_insights'];

    /** route parameter name => model it binds to */
    private const BINDINGS = ['account' => 'account', 'transaction' => 'transaction', 'category' => 'category', 'budget' => 'budget', 'rule' => 'rule', 'token' => 'token'];

    private User $me;

    private User $stranger;

    private Team $mine;       // a company $me belongs to

    private Team $theirs;     // a company $me does NOT belong to

    /** @var array<string, array<string, int|string>> context label => [param => id] */
    private array $rows = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->me = $this->user();
        $this->stranger = $this->user();
        $this->mine = $this->company($this->me, 'Mine Ltd');
        $this->theirs = $this->company($this->stranger, 'Theirs Ltd');

        $this->rows['my personal'] = $this->seedContext('personal', ['user_id' => $this->me->id, 'team_id' => null]);
        $this->rows['my company'] = $this->seedContext('mine', ['user_id' => null, 'team_id' => $this->mine->id], company: $this->mine);
        $this->rows['their company'] = $this->seedContext('theirs', ['user_id' => null, 'team_id' => $this->theirs->id], company: $this->theirs);
        $this->rows['their personal'] = $this->seedContext('stranger', ['user_id' => $this->stranger->id, 'team_id' => null]);
    }

    /** One row of every bindable kind in a context, named so a leak is readable. */
    private function seedContext(string $label, array $owner, ?Team $company = null): array
    {
        $account = Account::factory()->create($owner + ['name' => "Sentinel account {$label}", 'currency' => 'ZAR']);
        $other = Account::factory()->create($owner + ['name' => "Sentinel second {$label}", 'currency' => 'ZAR']);
        $category = Category::factory()->create($owner + ['name' => "Sentinel category {$label}", 'type' => 'expense']);
        $entry = $this->entryOn($account, ['category_id' => $category->id, 'description' => "Sentinel entry {$label}", 'type' => 'expense', 'date' => now()->toDateString()]);
        $budget = Budget::factory()->create($owner + ['category_id' => $category->id, 'currency' => 'ZAR']);
        $rule = RecurringRule::factory()->create($owner + ['account_id' => $account->id, 'description' => "Sentinel rule {$label}"]);
        Insight::create($owner + ['insight' => "Sentinel insight {$label}", 'type' => 'x', 'iso_week' => '2026-W0'.(strlen($label) % 9 + 1)]);

        // A staged import is bound to the context it was staged in.
        $token = (string) Str::uuid();
        Storage::disk('local')->put("finance-imports/{$this->me->id}/{$token}.csv", "date,description,amount\n2026-08-01,Sentinel row {$label},-1.00\n");
        Storage::disk('local')->put("finance-imports/{$this->me->id}/{$token}.json", json_encode(['account_id' => $account->id, 'filename' => 's.csv', 'context' => $this->keyFor($owner)]));

        if ($company === null) {
            app(TransferService::class)->create(User::findOrFail($owner['user_id']), $account, $other, 100, null, now(), "Sentinel transfer {$label}", null);
        } else {
            app(TransferService::class)->create($company->owner, $account, $other, 100, null, now(), "Sentinel transfer {$label}", null);
        }

        return ['account' => $account->id, 'transaction' => $entry->id, 'category' => $category->id, 'budget' => $budget->id, 'rule' => $rule->id, 'token' => $token, 'second_account' => $other->id];
    }

    private function keyFor(array $owner): string
    {
        return $owner['team_id'] !== null ? 'company:'.$owner['team_id'] : 'personal:'.$owner['user_id'];
    }

    /** A hash of every ledger row, deleted ones included. */
    private function fingerprint(): string
    {
        $out = [];
        foreach (self::LEDGER_TABLES as $table) {
            $out[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }

        return md5(json_encode($out));
    }

    /** @return list<LaravelRoute> every route that binds one of the ledger parameters */
    private function bindingRoutes(): array
    {
        $found = [];
        foreach (Route::getRoutes() as $route) {
            $params = $route->parameterNames();
            // Only this app's own routes (Fortify's reset-password/{token} is not a ledger route).
            if (str_starts_with($route->getActionName(), 'App\\Http\\Controllers\\') && array_intersect($params, array_keys(self::BINDINGS)) !== []) {
                $found[] = $route;
            }
        }

        return $found;
    }

    private function callerContexts(): array
    {
        return [
            'personal' => fn () => $this->inPersonal($this->me),
            'my company' => fn () => $this->inCompany($this->me, $this->mine),
        ];
    }

    private function send(LaravelRoute $route, array $params): TestResponse
    {
        $method = collect($route->methods())->first(fn ($m) => ! in_array($m, ['HEAD'], true));
        $uri = route($route->getName(), $params, false);

        $payload = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            ? ['amount' => '1.00', 'description' => 'smuggled', 'type' => 'expense', 'date' => now()->toDateString(), 'name' => 'smuggled', 'confirm' => '1', 'opening_balance' => '1.00', 'opening_balance_date' => now()->toDateString(), 'currency' => 'ZAR', 'category_id' => $params['category'] ?? null, 'account_id' => $params['account'] ?? null, 'recurrence_type' => 'monthly', 'recurrence_interval' => 1, 'month' => 1, 'year' => 2026, 'token' => $params['token'] ?? null, 'date_format' => 'ymd', 'amount_locale' => 'en', 'end_date' => now()->toDateString()]
            : [];

        return $this->call($method, $uri, $payload);
    }

    public function test_every_route_that_takes_a_ledger_id_is_known_to_this_test(): void
    {
        $unknown = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->getActionName(), 'App\\Http\\Controllers\\')) {
                continue;
            }
            foreach ($route->parameterNames() as $name) {
                if (! array_key_exists($name, self::BINDINGS)) {
                    $unknown[] = $route->methods()[0].' '.$route->uri().' {'.$name.'}';
                }
            }
        }

        $this->assertSame([], $unknown, 'These routes take an id this regression test does not smuggle yet');
        $this->assertGreaterThan(15, count($this->bindingRoutes()), 'expected the finance routes to be found');
    }

    public function test_smuggling_a_foreign_id_into_any_route_is_a_404_and_changes_nothing(): void
    {
        $attempts = 0;

        foreach ($this->callerContexts() as $callerLabel => $enter) {
            // Own context is a baseline the personal and company cases must NOT be confused with.
            $foreign = collect($this->rows)->except($callerLabel === 'personal' ? ['my personal'] : ['my company']);

            foreach ($foreign as $foreignLabel => $ids) {
                foreach ($this->bindingRoutes() as $route) {
                    $enter();
                    $params = [];
                    foreach ($route->parameterNames() as $name) {
                        $params[$name] = $ids[$name] ?? null;
                    }
                    $params = array_filter($params, fn ($v) => $v !== null);
                    if (count($params) !== count($route->parameterNames())) {
                        continue;
                    }

                    $before = $this->fingerprint();
                    $response = $this->send($route, $params);
                    $attempts++;

                    $label = "{$callerLabel} caller -> {$foreignLabel} id on ".implode('|', $route->methods()).' '.$route->uri();

                    // An import preview of a token from another context redirects to the upload page
                    // (that is how a stale or foreign token is always answered); everything else is a 404.
                    if ($route->getName() === 'transactions.import.preview') {
                        $response->assertRedirect(route('transactions.import.create'));
                    } else {
                        $this->assertSame(404, $response->getStatusCode(), $label.' should be 404, got '.$response->getStatusCode());
                    }

                    $this->assertSame($before, $this->fingerprint(), $label.' changed the database');
                }
            }
        }

        $this->assertGreaterThan(100, $attempts);
    }

    public function test_a_caller_in_a_company_they_do_not_belong_to_reaches_nothing_by_id_either(): void
    {
        $outsider = $this->user();

        foreach ($this->rows as $label => $ids) {
            foreach ($this->bindingRoutes() as $route) {
                $params = [];
                foreach ($route->parameterNames() as $name) {
                    $params[$name] = $ids[$name] ?? null;
                }
                $params = array_filter($params, fn ($v) => $v !== null);
                if (count($params) !== count($route->parameterNames()) || $route->getName() === 'transactions.import.preview') {
                    continue;
                }

                // The session claims membership of a company the outsider is not in.
                $this->inCompany($outsider, $this->mine);
                $before = $this->fingerprint();
                $response = $this->send($route, $params);

                $this->assertSame(404, $response->getStatusCode(), "outsider -> {$label} on ".$route->uri().' should be 404');
                $this->assertSame($before, $this->fingerprint());
            }
        }
    }

    public function test_the_list_and_report_pages_never_show_a_row_from_another_context(): void
    {
        $pages = ['dashboard', 'transactions.index', 'accounts.index', 'categories.index', 'budgets.index', 'recurring.index', 'reports.cash-flow', 'forecast.index', 'insights.index', 'audit.index', 'reconciliation.index', 'transactions.create', 'transactions.import.create', 'transfers.create'];

        $cases = [
            ['personal', fn () => $this->inPersonal($this->me), 'personal'],
            ['my company', fn () => $this->inCompany($this->me, $this->mine), 'mine'],
        ];

        foreach ($cases as [$label, $enter, $own]) {
            foreach ($pages as $page) {
                $html = $enter()->get(route($page))->assertOk()->getContent();

                foreach (['personal', 'mine', 'theirs', 'stranger'] as $other) {
                    if ($other === $own) {
                        continue;
                    }
                    foreach (['account', 'second', 'category', 'entry', 'rule', 'insight', 'transfer', 'row'] as $kind) {
                        $this->assertStringNotContainsString("Sentinel {$kind} {$other}", $html, "{$page} in the {$label} ledger leaked '{$kind}' from {$other}");
                    }
                }
            }
        }
    }

    public function test_the_ledger_pages_do_show_their_own_rows_so_the_leak_test_is_not_vacuous(): void
    {
        $this->inPersonal($this->me)->get(route('accounts.index'))->assertSee('Sentinel account personal');
        $this->inCompany($this->me, $this->mine)->get(route('accounts.index'))->assertSee('Sentinel account mine');
        $this->get(route('transactions.index'))->assertSee('Sentinel entry mine');
        $this->get(route('categories.index'))->assertSee('Sentinel category mine');
        $this->get(route('recurring.index'))->assertSee('Sentinel rule mine');
    }

    // ── foreign ids smuggled in the request body ───────────────────────────

    public function test_a_foreign_account_or_category_in_a_form_body_is_rejected_and_writes_nothing(): void
    {
        $day = now()->toDateString();

        $cases = [
            'my company' => [fn () => $this->inCompany($this->me, $this->mine), ['personal' => $this->rows['my personal'], 'their company' => $this->rows['their company'], 'their personal' => $this->rows['their personal']], $this->rows['my company']],
            'personal' => [fn () => $this->inPersonal($this->me), ['company' => $this->rows['my company'], 'their company' => $this->rows['their company'], 'their personal' => $this->rows['their personal']], $this->rows['my personal']],
        ];

        foreach ($cases as $label => [$enter, $foreigners, $own]) {
            foreach ($foreigners as $whose => $foreign) {
                $attempts = [
                    'transaction in a foreign account' => ['post', route('transactions.store'), ['account_id' => $foreign['account'], 'description' => 'x', 'amount' => '1.00', 'type' => 'expense', 'date' => $day]],
                    'transaction with a foreign category' => ['post', route('transactions.store'), ['account_id' => $own['account'], 'category_id' => $foreign['category'], 'description' => 'x', 'amount' => '1.00', 'type' => 'expense', 'date' => $day]],
                    'edit own transaction into a foreign account' => ['put', route('transactions.update', $own['transaction']), ['account_id' => $foreign['account'], 'description' => 'x', 'amount' => '1.00', 'type' => 'expense', 'date' => $day]],
                    'edit own transaction with a foreign category' => ['put', route('transactions.update', $own['transaction']), ['account_id' => $own['account'], 'category_id' => $foreign['category'], 'description' => 'x', 'amount' => '1.00', 'type' => 'expense', 'date' => $day]],
                    'transfer from a foreign account' => ['post', route('transfers.store'), ['from_account_id' => $foreign['account'], 'to_account_id' => $own['account'], 'amount' => '1.00', 'date' => $day]],
                    'transfer to a foreign account' => ['post', route('transfers.store'), ['from_account_id' => $own['account'], 'to_account_id' => $foreign['account'], 'amount' => '1.00', 'date' => $day]],
                    'budget for a foreign category' => ['post', route('budgets.store'), ['category_id' => $foreign['category'], 'amount' => '1.00', 'currency' => 'ZAR', 'month' => 3, 'year' => 2027]],
                    'edit own budget onto a foreign category' => ['put', route('budgets.update', $own['budget']), ['category_id' => $foreign['category'], 'amount' => '1.00', 'currency' => 'ZAR', 'month' => 3, 'year' => 2027]],
                    'edit own rule onto a foreign category' => ['put', route('recurring.update', $own['rule']), ['description' => 'x', 'amount' => '1.00', 'category_id' => $foreign['category'], 'recurrence_type' => 'monthly', 'recurrence_interval' => 1]],
                    'import into a foreign account' => ['post', route('transactions.import.upload'), ['account_id' => $foreign['account'], 'file' => UploadedFile::fake()->createWithContent('s.csv', "date,description,amount\n2026-08-01,x,-1.00\n")]],
                ];

                foreach ($attempts as $what => [$method, $url, $body]) {
                    $enter();
                    $before = $this->fingerprint();
                    $response = $this->{$method}($url, $body);

                    $this->assertContains($response->getStatusCode(), [302, 422], "{$label} / {$whose} / {$what}");
                    $this->assertTrue(session()->has('errors'), "{$label} / {$whose} / {$what} should be refused with an error");
                    $this->assertSame($before, $this->fingerprint(), "{$label} / {$whose} / {$what} changed the database");
                }
            }
        }
    }

    public function test_the_import_commit_refuses_a_token_from_another_context_and_writes_nothing(): void
    {
        $this->inCompany($this->me, $this->mine);
        $before = $this->fingerprint();

        $this->post(route('transactions.import.store'), ['token' => $this->rows['my personal']['token'], 'date_format' => 'ymd', 'amount_locale' => 'en'])
            ->assertSessionHasErrors('token');

        $this->assertSame($before, $this->fingerprint());
    }

    public function test_exports_and_every_other_get_route_without_an_id_is_scoped_too(): void
    {
        // Every parameterless GET route of the app must answer in both contexts without showing a foreign sentinel.
        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true) || $route->parameterNames() !== [] || ! str_starts_with($route->getActionName(), 'App\\Http\\Controllers\\')) {
                continue;
            }
            if (in_array($route->getName(), ['ecosystem.auth', null], true)) {
                continue;
            }

            foreach ([fn () => $this->inPersonal($this->me), fn () => $this->inCompany($this->me, $this->mine)] as $i => $enter) {
                $response = $enter()->get('/'.ltrim($route->uri(), '/'));
                if ($response->getStatusCode() >= 300) {
                    continue;
                }
                $own = $i === 0 ? 'personal' : 'mine';
                foreach (['personal', 'mine', 'theirs', 'stranger'] as $other) {
                    if ($other !== $own) {
                        $this->assertStringNotContainsString("Sentinel account {$other}", $response->getContent(), $route->uri().' leaked');
                        $this->assertStringNotContainsString("Sentinel entry {$other}", $response->getContent(), $route->uri().' leaked');
                    }
                }
            }
        }
        $this->addToAssertionCount(1);
    }
}
