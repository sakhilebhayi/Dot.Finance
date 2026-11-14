<?php

namespace App\Http\Middleware;

use App\Models\Team;
use App\Models\User;
use App\Services\InfoDot\EntitlementClient;
use App\Support\EntitlementRefusal;
use App\Support\Ledger\LedgerContext;
use App\Support\Ledger\LedgerContextManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The signed-in area honours the company's entitlement (R-50).
 *
 * InfoDot decides whether a company holds Dot.Finance. A person already signed in must stop working in a company's
 * books when that ends, so each request in the signed-in area asks (through a ten-minute cache) whether the company
 * whose ledger is active still holds the platform. A clear "no" answers with the plain refusal page and HTTP 403:
 * nothing is read from or written to the books. Anything else, including every kind of failure, lets the request
 * through exactly as before.
 *
 * Which company: the one named by a {team} in the address (Jetstream's team pages) when the person belongs to it,
 * otherwise the active ledger (LedgerContextManager). The personal ledger is not a company: InfoDot is never asked
 * about it; what redeem said about the person's personal workspace at sign-in (PERSONAL) decides, and only an exact
 * `false` refuses.
 *
 * It sits right after ResolveLedgerContext in every signed-in route group (a test lists them), on Jetstream's own
 * pages (config/jetstream.php), and is a Livewire persistent middleware.
 *
 * NEVER checked, by design (the money-in and integration paths, and anything a signed-out person reaches):
 *  - the signed server-to-server routes under /api (the Dot.Emall sales feed, the InfoDot membership webhook),
 *    which are in no group that carries this middleware;
 *  - the public invoice and quote links (/s/{token}), the CSP report, health, the home page, the cookie page;
 *  - sign-in, the handoff receiver, sign-out and password reset;
 *  - every scheduled command and queued job (they are not HTTP requests, so no middleware runs);
 *  - inside the signed-in area, only what keeps a person able to leave a refused company: the ledger switch,
 *    Jetstream's company switch, the person's own account page (where they export or erase their own data), terms,
 *    privacy and invitations.
 */
class EnsureCompanyEntitled
{
    /** Routes inside the checked groups that must keep working for a person whose company is refused. */
    private const EXEMPT = [
        'ledger.context.switch', // switching to another ledger
        'current-team.update',   // Jetstream's switch to another company
        'profile.show',          // the person's own account: their data, export and erasure
        'terms.show', 'policy.show', 'team-invitations.accept',
    ];

    /** Session key: redeem's `entitled` for the person's personal workspace, remembered at sign-in (absent: not set). */
    public const PERSONAL = 'infodot.personal_entitled';

    public function __construct(
        private readonly EntitlementClient $entitlements,
        private readonly LedgerContextManager $contexts,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->entitlements->enabled() || $request->routeIs(...self::EXEMPT)) {
            return $next($request);
        }

        $user = $request->user() ?? Auth::guard('web')->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $team = $this->company($request, $user);

        // The personal ledger, a team InfoDot does not know, or no ledger at all: the read endpoint is never asked.
        if (! $team) {
            if ($request->hasSession() && $request->session()->get(self::PERSONAL) === false && $this->contexts->current()?->isCompany() !== true) {
                return $this->refuse($request, fn () => EntitlementRefusal::response('company_required', null, $this->others($user, null)));
            }

            return $next($request);
        }

        $answer = $this->entitlements->forCompany((string) $team->infodot_team_uuid);

        if ($answer === null || $answer['entitled'] === true) {
            return $next($request);
        }

        return $this->refuse($request, fn () => EntitlementRefusal::response($answer['status'], $team->name, $this->others($user, $team)));
    }

    /**
     * Livewire throws away any non-redirect response a persistent middleware returns, so a refusal page would let a
     * component update run. On a Livewire update the refusal must abort: nothing runs and no component comes back.
     */
    private function refuse(Request $request, Closure $page): Response
    {
        if ($request->headers->has('X-Livewire') || request()->headers->has('X-Livewire')) {
            abort(403);
        }

        return $page();
    }

    /** The company whose books this request opens, or null for the personal ledger (or a team with no InfoDot uuid). */
    private function company(Request $request, User $user): ?Team
    {
        $named = $request->route('team');

        if (is_scalar($named)) {
            $named = $user->allTeams()->firstWhere('id', (int) $named);
        }

        if ($named instanceof Team && $user->belongsToTeam($named)) {
            return $named->isCompany() ? $named : null;
        }

        $context = $this->contexts->current();

        if ($context === null || ! $context->isCompany()) {
            return null;
        }

        $team = Team::find($context->teamId);

        return $team instanceof Team && $team->isCompany() ? $team : null;
    }

    /**
     * The other ledgers the person may switch to. The personal ledger is left out when InfoDot said it does not hold
     * the platform. No answer from InfoDot is asked for here: another company is simply offered, and is checked on arrival.
     *
     * @return list<object{name: string, value: string}>
     */
    private function others(User $user, ?Team $current): array
    {
        $personalRefused = session(self::PERSONAL) === false;

        return collect($this->contexts->contextsFor($user))
            ->reject(fn (LedgerContext $c) => $c->isCompany() ? $current?->id === $c->teamId : ($current === null || $personalRefused))
            ->map(fn (LedgerContext $c) => (object) ['name' => $c->label(), 'value' => $c->isCompany() ? (string) $c->teamId : 'personal'])
            ->values()
            ->all();
    }
}
