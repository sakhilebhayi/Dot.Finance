<?php

namespace Tests\Concerns;

use App\Models\Team;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;

/**
 * Shared machinery for the R-44 security sweep: walk the application's own routes, fill their parameters with the ids
 * of one ledger, call each as a chosen person, and snapshot every ledger table before and after.
 */
trait RunsRouteProbes
{
    /** @var list<string>|null */
    private ?array $ledgerTables = null;

    /** Every table that carries the ledger owner pair (user_id, team_id), found from the schema, so a new table is covered at once. */
    protected function ledgerTables(): array
    {
        if ($this->ledgerTables !== null) {
            return $this->ledgerTables;
        }

        $tables = [];
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            if (Schema::hasColumns($name, ['user_id', 'team_id']) && ! in_array($name, ['ledger_member_roles'], true)) {
                $tables[] = $name;
            }
        }
        // The role grants and the team membership decide who may act, so they are part of what an attack must not change.
        $tables = array_merge($tables, ['ledger_member_roles', 'team_user', 'teams']);
        sort($tables);

        return $this->ledgerTables = array_values(array_unique($tables));
    }

    /**
     * A hash of every ledger row, deleted ones included. With $teamId the hash is only that company's rows (the rows an
     * outsider must never be able to touch while they legitimately change their own).
     */
    protected function fingerprint(?int $teamId = null): string
    {
        $out = [];
        foreach ($this->ledgerTables() as $table) {
            $query = DB::table($table);
            if ($teamId !== null) {
                if (! Schema::hasColumn($table, 'team_id')) {
                    continue;
                }
                $query->where('team_id', $teamId);
            }
            if (Schema::hasColumn($table, 'id')) {
                $query->orderBy('id');
            }
            $out[$table] = $query->get()->map(fn ($r) => (array) $r)->all();
        }

        return md5(json_encode($out));
    }

    /** @return list<LaravelRoute> */
    protected function appRoutes(): array
    {
        $found = [];
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->getActionName(), 'App\\Http\\Controllers\\')) {
                $found[] = $route;
            }
        }

        return $found;
    }

    protected function methodOf(LaravelRoute $route): string
    {
        return (string) collect($route->methods())->first(fn ($m) => ! in_array($m, ['HEAD', 'OPTIONS'], true));
    }

    protected function isWrite(LaravelRoute $route): bool
    {
        return in_array($this->methodOf($route), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    /** The parameters of $route filled from $ids, or null when this ledger has no row for one of them. */
    protected function paramsFor(LaravelRoute $route, array $ids): ?array
    {
        $params = [];
        foreach ($route->parameterNames() as $name) {
            if (! array_key_exists($name, $ids)) {
                return null;
            }
            $params[$name] = $ids[$name];
        }

        return $params;
    }

    /** A form body generous enough to pass most validation, so a refusal is the authorisation and not a missing field. */
    protected function bodyFor(LaravelRoute $route, array $ids, array $extra = []): array
    {
        if (! $this->isWrite($route)) {
            return $extra;
        }

        $day = now()->toDateString();

        return $extra + [
            'amount' => '1.00', 'amount_cents' => 100, 'description' => 'probe', 'type' => 'expense', 'date' => $day, 'name' => 'probe', 'confirm' => '1', 'reason' => 'probe',
            'opening_balance' => '1.00', 'opening_balance_date' => $day, 'currency' => 'ZAR', 'category_id' => $ids['category'] ?? null, 'account_id' => $ids['account'] ?? null,
            'recurrence_type' => 'monthly', 'recurrence_interval' => 1, 'month' => 1, 'year' => 2026, 'token' => $ids['token'] ?? null, 'date_format' => 'ymd',
            'amount_locale' => 'en', 'end_date' => $day, 'party_id' => $ids['party'] ?? null, 'paid_on' => $day, 'cash_account_id' => $ids['account'] ?? null,
            'entry_date' => $day, 'issue_date' => $day, 'bill_date' => $day, 'title' => 'probe', 'idempotency_key' => 'probe-'.bin2hex(random_bytes(4)),
            'role' => 'viewer', 'file' => UploadedFile::fake()->createWithContent('probe.csv', "date,description,amount\n2026-08-01,probe,-1.00\n"),
        ];
    }

    /** What happened, in a few words that a golden table can hold. */
    protected function classify(TestResponse $response): string
    {
        $status = $response->getStatusCode();

        return match (true) {
            $status === 503 => 'unavailable',
            $status >= 500 => 'error',
            $status === 401 => 'unauthenticated',
            $status === 419 => 'csrf',
            $status === 403 => 'forbidden',
            $status === 404 => 'not-found',
            $status === 405 => 'method',
            $status === 429 => 'throttled',
            $status === 422 => 'invalid',
            $status >= 300 && $status < 400 => $this->classifyRedirect($response),
            $status >= 200 && $status < 300 => 'ok',
            default => 'other-'.$status,
        };
    }

    private function classifyRedirect(TestResponse $response): string
    {
        $location = (string) $response->headers->get('Location');
        if (str_contains($location, '/login')) {
            return 'unauthenticated';
        }
        if (str_contains($location, '/email/verify')) {
            return 'unverified';
        }

        // The errors bag is a ViewErrorBag during the request and its array form once the test session has stored it.
        $errors = session('errors');
        $hasErrors = $errors instanceof \Illuminate\Support\ViewErrorBag ? $errors->count() > 0 : (is_array($errors) && $errors !== []);

        return $hasErrors ? 'invalid' : 'redirect';
    }

    /** Sign in as $user in the given context ('personal', or a company), or as nobody. */
    protected function actAs(?User $user, string|Team|null $context): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        Cache::flush();

        if ($user === null) {
            return $this;
        }

        return $context instanceof Team ? $this->inCompany($user, $context) : $this->inPersonal($user);
    }

    /**
     * One request, inside a savepoint that is rolled back afterwards so every probe starts from the same data.
     *
     * @return array{0: TestResponse, 1: bool} the response, and whether any watched ledger row changed
     */
    protected function probe(callable $request, ?int $teamPartition = null): array
    {
        DB::beginTransaction();
        try {
            $before = $this->fingerprint($teamPartition);
            $response = $request();
            $changed = $before !== $this->fingerprint($teamPartition);
        } finally {
            DB::rollBack();
        }

        return [$response, $changed];
    }
}
