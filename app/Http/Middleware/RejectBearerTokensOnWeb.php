<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The application's pages and forms are for a signed-in browser session. Every ledger route is behind `auth:sanctum`, and that
 * guard accepts a personal access token as well as a session, so without this a token meant for the API (or the one-time
 * InfoDot handoff token) would open the whole web application: every page of every ledger the person belongs to, and, because a
 * non-browser client can send `Sec-Fetch-Site: same-origin`, quite possibly its forms too. A request to the web group that
 * carries a Bearer token is therefore refused outright. The `api` group (routes/api.php) is untouched and keeps tokens.
 * (QA security D-1405.)
 */
class RejectBearerTokensOnWeb
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() !== null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
