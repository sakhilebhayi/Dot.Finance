<?php

use App\Http\Middleware\ResolveLedgerContext;
use App\Support\Ledger\LedgerContextViolation;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A write that would cross ledgers is a bug in the caller, never something to show a person:
        // it is logged, and answered as a missing page.
        $exceptions->render(fn (LedgerContextViolation $e, Request $request) => abort(404));

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
