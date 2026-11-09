<?php

namespace Tests\Feature\Security;

use App\Enums\FinanceRole;
use App\Models\Controls\LedgerMemberRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\TaxHolidaysSeeder;
use Database\Seeders\TaxModuleRulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\Concerns\RunsRouteProbes;
use Tests\Concerns\SeedsSentinelLedgers;
use Tests\TestCase;

/**
 * R-44 security sweep, part 1: the authorisation matrix.
 *
 * Every route of the application (found from the route list, so a route added later is covered with no edit here) is
 * called as nine kinds of person against ONE company ledger ("Mine Ltd") whose own ids are filled into the URL:
 *
 *   owner, manager, accountant, bookkeeper, approver, viewer   the six finance roles inside Mine Ltd
 *   foreign-owner      owns ANOTHER company ledger and sits in it, smuggling Mine Ltd's ids
 *   personal-user      has only a personal ledger, smuggling Mine Ltd's ids
 *   outsider           belongs to nothing but claims Mine Ltd in the session
 *   guest              not signed in
 *
 * Each request runs inside a savepoint that is rolled back, with every ledger table hashed before and after.
 * The assertions are properties, not a hand-kept list, so a new route cannot slip past:
 *   - nothing ever answers 5xx;
 *   - a guest reaches only the documented public routes;
 *   - foreign-owner, personal-user and outsider never get a 200/redirect-success from a route that takes a Mine Ltd id,
 *     and never change a single row of Mine Ltd;
 *   - a viewer and an approver change nothing anywhere except through the routes listed in WRITES_BY_ROLE;
 *   - the observed outcome of every (route, person) pair is written to docs/R44_QA_security_matrix.md when
 *     SECURITY_MATRIX_OUT is set (that is how the matrix in the findings document is produced).
 */
class AuthorisationMatrixTest extends TestCase
{
    use BuildsCompanyLedgers;
    use RefreshDatabase;
    use RunsRouteProbes;
    use SeedsSentinelLedgers;

    /** Routes a signed-out visitor may reach on purpose, and why. */
    private const PUBLIC_ROUTES = [
        'ecosystem.auth' => 'sign-in handoff from InfoDot (signed token in the POST body)',
        'api.ecosystem.membership' => 'signed server-to-server webhook',
        'api.integrations.emall.sales' => 'signed server-to-server delivery',
        'tax.payroll-import' => 'signed server-to-server import',
        'csp.report' => 'browsers post CSP violation reports without a session',
        'sales.share.show' => 'public share link (token)',
        'sales.share.pdf' => 'public share link (token)',
        'cookies' => 'cookie policy',
    ];

    private User $me;

    private User $stranger;

    private Team $mine;

    private Team $theirs;

    /** @var array<string, User> */
    private array $people = [];

    private User $personalUser;

    private User $outsider;

    private array $mineIds;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->me = $this->user();
        $this->stranger = $this->user();
        $this->mine = $this->company($this->me, 'Mine Ltd');
        $this->theirs = $this->company($this->stranger, 'Theirs Ltd');

        $this->people = [
            'owner' => $this->me,
            'manager' => $this->memberOf($this->mine, 'admin'),
            'accountant' => $this->memberOf($this->mine, 'editor'),
            'bookkeeper' => $this->memberOf($this->mine, 'editor'),
            'approver' => $this->memberOf($this->mine, 'viewer'),
            'viewer' => $this->memberOf($this->mine, 'viewer'),
        ];
        foreach (['accountant' => FinanceRole::Accountant, 'approver' => FinanceRole::Approver] as $who => $role) {
            LedgerMemberRole::create(['team_id' => $this->mine->id, 'member_user_id' => $this->people[$who]->id, 'role' => $role->value, 'granted_by' => $this->me->id, 'granted_at' => now()]);
        }
        $this->seed(TaxModuleRulesSeeder::class);
        $this->seed(TaxHolidaysSeeder::class);
        $this->personalUser = $this->user();
        $this->outsider = $this->user();

        $this->mineIds = $this->seedContext('mine', ['user_id' => null, 'team_id' => $this->mine->id], company: $this->mine);
        $this->seedContext('theirs', ['user_id' => null, 'team_id' => $this->theirs->id], company: $this->theirs);
    }

    /** @return array<string, array{0: ?User, 1: string|Team|null}> label => [person, context] */
    private function identities(): array
    {
        return [
            'owner' => [$this->people['owner'], $this->mine],
            'manager' => [$this->people['manager'], $this->mine],
            'accountant' => [$this->people['accountant'], $this->mine],
            'bookkeeper' => [$this->people['bookkeeper'], $this->mine],
            'approver' => [$this->people['approver'], $this->mine],
            'viewer' => [$this->people['viewer'], $this->mine],
            'foreign-owner' => [$this->stranger, $this->theirs],
            'personal-user' => [$this->personalUser, 'personal'],
            'outsider' => [$this->outsider, $this->mine],
            'guest' => [null, null],
        ];
    }

    /** The ids of Mine Ltd for $route: the generic set, with the few routes that need a different kind of row. */
    private function idsFor(LaravelRoute $route): array
    {
        $ids = $this->mineIds;
        $name = (string) $route->getName();

        if (str_starts_with($name, 'purchases.suppliers.')) {
            $ids['party'] = $this->mineIds['supplierParty'];
        }
        if (str_starts_with($name, 'purchases.receipts.')) {
            $ids['attachment'] = $this->mineIds['receiptAttachment'];
        }
        if (str_starts_with($name, 'reports.exports.')) {
            $ids['reportExport'] = $this->mineIds['doneExport'];
        }

        return $ids;
    }

    private function send(LaravelRoute $route, array $params, array $body)
    {
        $uri = route($route->getName() ?? '', $params, false);
        $files = array_filter($body, fn ($v) => $v instanceof UploadedFile);
        $body = array_diff_key($body, $files);

        return $this->call($this->methodOf($route), $uri, $body, [], $files);
    }

    /** @return array<string, array<string, string>> route label => identity => outcome */
    private function runMatrix(?callable $onResult = null): array
    {
        $matrix = [];

        foreach ($this->appRoutes() as $route) {
            $name = $route->getName();
            if ($name === null) {
                continue;
            }
            $ids = $this->idsFor($route);
            $params = $this->paramsFor($route, $ids);
            if ($params === null) {
                continue;
            }
            $label = $this->methodOf($route).' /'.ltrim($route->uri(), '/');

            foreach ($this->identities() as $who => [$user, $context]) {
                $partition = in_array($who, ['foreign-owner', 'personal-user', 'outsider'], true) ? $this->mine->id : null;
                [$response, $changed] = $this->probe(function () use ($user, $context, $route, $params, $ids, $name) {
                    return $this->actAs($user, $context)->send($route, $params, $this->bodyFor($route, $ids, $name === 'ledger.context.switch' ? ['context' => 'personal'] : []));
                }, $partition);

                $outcome = $this->classify($response);
                $matrix[$label][$who] = $outcome.($changed ? '+CHANGED' : '');
                $onResult && $onResult($route, $name, $who, $outcome, $changed, $response);
            }
        }

        return $matrix;
    }

    public function test_the_matrix_holds_for_every_route_and_every_person(): void
    {
        $problems = [];
        $routesSeen = 0;
        $passed = [];

        $matrix = $this->runMatrix(function (LaravelRoute $route, string $name, string $who, string $outcome, bool $changed, $response) use (&$problems, &$routesSeen, &$passed) {
            $routesSeen++;
            $where = "{$who} on ".$this->methodOf($route).' /'.ltrim($route->uri(), '/')." [{$name}] -> {$outcome}";

            // Every response of every kind (page, redirect, download, error) carries the enforced policy with a nonce.
            $csp = (string) $response->headers->get('Content-Security-Policy');
            if (! preg_match("/script-src 'self' 'nonce-[A-Za-z0-9+\/=]{20,}'/", $csp) || $response->headers->get('X-Content-Type-Options') !== 'nosniff' || $response->headers->get('X-Frame-Options') !== 'DENY') {
                $problems[] = "MISSING SECURITY HEADERS: {$where}";
            }

            // A foreign caller (or a guest) must never read a sentinel row of Mine Ltd, whichever page they are given.
            if (in_array($who, ['foreign-owner', 'personal-user', 'outsider', 'guest'], true) && $name !== 'sales.share.show'
                && preg_match('/Sentinel [a-z ]+ mine\b/', (string) $response->getContent()) === 1) {
                $problems[] = "LEAK OF MINE LTD ROW: {$where}";
            }

            if ($outcome === 'error') {
                $problems[] = "SERVER ERROR: {$where}: ".substr((string) ($response->exception?->getMessage()), 0, 200).' @ '.basename((string) $response->exception?->getFile()).':'.$response->exception?->getLine();
            }

            if ($who === 'guest') {
                if (! array_key_exists($name, self::PUBLIC_ROUTES) && $outcome !== 'unauthenticated') {
                    $problems[] = "GUEST REACHED A NON-PUBLIC ROUTE: {$where}";
                }
                if ($changed) {
                    $problems[] = "GUEST CHANGED DATA: {$where}";
                }

                return;
            }

            if (in_array($who, ['foreign-owner', 'personal-user', 'outsider'], true)) {
                if ($changed) {
                    $problems[] = "FOREIGN CALLER CHANGED MINE LTD: {$where}";
                }
                // A staged-import token from another ledger is always answered by sending the person back to the upload page
                // (the staged file is bound to the context it was staged in; ContextIsolationRoutesTest proves that redirect).
                $staleToken = $name === 'transactions.import.preview' || $name === 'bank.import.preview';
                if (! in_array($outcome, ['not-found', 'forbidden', 'method'], true) && ! ($staleToken && $outcome === 'redirect') && $this->hasIdParameter($route)) {
                    $problems[] = "FOREIGN CALLER GOT PAST AN ID: {$where}";
                }

                return;
            }

            if (in_array($who, ['viewer', 'approver'], true) && $changed && ! $this->writeAllowed($name, $who)) {
                $problems[] = "READ-ONLY ROLE CHANGED DATA: {$where}";
            }

            if (in_array($who, ['owner', 'manager', 'accountant', 'bookkeeper', 'approver', 'viewer'], true)) {
                $passed[$this->methodOf($route).' /'.ltrim($route->uri(), '/')][$who] = ! in_array($outcome, ['forbidden', 'not-found', 'unauthenticated', 'method'], true);
            }
        });

        // The gate pass-set of every route (which roles get past the authorisation check) is frozen in a reviewed table.
        $observed = [];
        foreach ($passed as $label => $byRole) {
            $observed[$label] = collect(['owner' => 'O', 'manager' => 'M', 'accountant' => 'A', 'bookkeeper' => 'B', 'approver' => 'P', 'viewer' => 'V'])
                ->filter(fn ($letter, $role) => $byRole[$role] ?? false)->implode('');
        }
        ksort($observed);
        $goldenFile = __DIR__.'/matrix_golden.php';
        if (getenv('SECURITY_MATRIX_REGEN')) {
            file_put_contents($goldenFile, "<?php\n\n// Reviewed gate pass-sets: O owner, M finance manager, A accountant, B bookkeeper, P approver, V viewer.\n// Regenerate with SECURITY_MATRIX_REGEN=1 and READ THE DIFF: a letter appearing is a permission being granted.\nreturn ".var_export($observed, true).";\n");
        }
        $golden = is_file($goldenFile) ? require $goldenFile : [];
        foreach ($observed as $label => $set) {
            if (! array_key_exists($label, $golden)) {
                $problems[] = "NEW ROUTE NOT IN THE REVIEWED MATRIX: {$label} lets [{$set}] past its gate. Review it, then regenerate the golden table.";
            } elseif ($golden[$label] !== $set) {
                $problems[] = "GATE CHANGED: {$label} let [{$golden[$label]}] past and now lets [{$set}] past.";
            }
        }
        foreach (array_diff_key($golden, $observed) as $label => $set) {
            $problems[] = "ROUTE GONE OR UNREACHED: {$label} (was [{$set}])";
        }

        $this->maybeDump($matrix);

        $this->assertGreaterThan(1500, $routesSeen, 'the sweep should cover hundreds of routes for ten kinds of person');
        $this->assertSame([], $problems, implode("\n", $problems));
    }

    private function hasIdParameter(LaravelRoute $route): bool
    {
        return $route->parameterNames() !== [] && $route->getName() !== 'sales.share.show' && $route->getName() !== 'sales.share.pdf';
    }

    /**
     * The only routes through which a viewer or an approver may change data, and why. Everything else a read-only role
     * calls must leave every ledger table untouched.
     */
    private function writeAllowed(string $routeName, string $who): bool
    {
        $always = [
            'ledger.context.switch' => 'choosing which ledger is showing is the person\'s own session',
            'intel.notifications.read' => 'marking one\'s own notification read',
            'intel.notifications.read-all' => 'marking one\'s own notifications read',
            'intel.notifications.preferences.update' => 'one\'s own notification preferences',
            'intel.assistant.ask' => 'asking the assistant stores the question and answer; it can only read',
            'intel.assistant.clear' => 'clearing one\'s own assistant history',
            'intel.assistant.destroy' => 'removing one of one\'s own assistant messages',
            'intel.assistant.draft.dismiss' => 'dismissing one\'s own draft',
            'reports.exports.store' => 'queueing a report the person may view',
            'reports.presets.store' => 'saving one\'s own report view',
            'reports.presets.destroy' => 'removing one\'s own report view',
            'intel.attention.scan' => 'refreshing the findings the person can read',
            'intel.attention.dismiss' => 'dismissing a finding',
            'intel.attention.snooze' => 'snoozing a finding',
            'sales.customers.statement.csv' => 'a per-customer statement download is open to readers and writes only its audit row (D-1412)',
            'purchases.suppliers.statement' => 'a per-supplier statement download (?format=csv) is open to readers and writes only its audit row (D-1412)',
        ];
        $approver = [
            'controls.approvals.approve' => 'approving is what an approver is for',
            'controls.approvals.reject' => 'rejecting is what an approver is for',
            'controls.approvals.cancel' => 'cancelling one\'s own request',
            'purchases.bills.void' => 'the capability matrix gives voiding a bill to approve-bill (a reversing entry is posted, the bill stays)',
            'purchases.claims.void' => 'voiding a claim is approve-expense',
            'purchases.bank.approve' => 'approving changed supplier bank details is approve-payment',
            'purchases.bank.reject' => 'rejecting changed supplier bank details is approve-payment',
        ];

        return array_key_exists($routeName, $always) || ($who === 'approver' && array_key_exists($routeName, $approver));
    }

    private function maybeDump(array $matrix): void
    {
        $out = getenv('SECURITY_MATRIX_OUT');
        if (! $out) {
            return;
        }

        $who = array_keys($this->identities());
        $lines = ['| Route | '.implode(' | ', $who).' |', '|---|'.str_repeat('---|', count($who))];
        ksort($matrix);
        foreach ($matrix as $label => $cells) {
            $lines[] = '| `'.$label.'` | '.implode(' | ', array_map(fn ($w) => $cells[$w] ?? '', $who)).' |';
        }
        file_put_contents($out, implode("\n", $lines)."\n");
    }
}
