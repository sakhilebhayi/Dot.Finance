<?php

namespace App\Http\Middleware;

use App\Support\Ledger\LedgerContext;
use App\Support\Ledger\LedgerContextManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Settles which ledger a signed-in request is about BEFORE route-model binding runs, so
 * every id in the URL is looked up inside that ledger only.
 *
 *  - A GET carrying ?context=personal or ?context=<company team id> (a deep link) names the
 *    ledger for this request and the rest of the session. A context the person may not use
 *    is a 404: nothing is shown and nothing is changed.
 *  - Otherwise the session's choice is used. If it is stale (the person left that company
 *    since), the session falls back to the personal ledger and says so, rather than showing
 *    nothing or, worse, someone else's data.
 */
class ResolveLedgerContext
{
    public function __construct(private readonly LedgerContextManager $contexts) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if ($request->isMethodSafe() && $request->query->has('context')) {
            $named = $this->contexts->resolve($user, $request->query->all()['context']);

            abort_if($named === null, 404);

            $this->contexts->remember($named);

            return $next($request);
        }

        if ($this->contexts->resolve($user, $this->contexts->stored()) === null) {
            $this->contexts->remember(LedgerContext::personal($user));
            $request->session()->flash('status', "You no longer have access to that company's ledger, so your personal ledger is showing.");
        }

        return $next($request);
    }
}
