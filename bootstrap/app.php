<?php

use App\Http\Middleware\RejectBearerTokensOnWeb;
use App\Http\Middleware\ResolveLedgerContext;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Ledger\LedgerContextViolation;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The active ledger must be settled before route-model binding looks any id up.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ResolveLedgerContext::class);

        // Enforced nonce Content-Security-Policy plus the baseline browser headers, on every response (error pages
        // included). Registered here and not in a route file because production runs route:cache.
        $middleware->append(SecurityHeaders::class);

        // A personal access token never opens the pages and forms of the web application (only the `api` group takes tokens).
        $middleware->web(prepend: [RejectBearerTokensOnWeb::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A write that would cross ledgers is a bug in the caller, never something to show a person:
        // it is logged, and answered as a missing page.
        $exceptions->render(fn (LedgerContextViolation $e, Request $request) => abort(404));

        // A URL no route matches never passes the web middleware, so the 404 page would not know who is signed in and offered "Sign in" to
        // a person who is. When the request carries a session cookie, decrypt it and start the session for that one response, so the page
        // leads a signed-in person back to the Command centre. A request with no cookie (a bot, a first visit) starts nothing and writes nothing.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*') || $request->hasSession() || ! $request->cookies->has(config('session.cookie'))) {
                return null;
            }

            return app(EncryptCookies::class)->handle($request, fn (Request $decrypted) => app(StartSession::class)->handle(
                $decrypted,
                fn (Request $started) => response()->view('errors.404', ['exception' => $e], 404),
            ));
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
