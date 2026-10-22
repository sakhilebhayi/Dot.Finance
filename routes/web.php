<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CashFlowController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ForecastController;
use App\Http\Controllers\InsightController;
use App\Http\Controllers\LedgerContextController;
use App\Http\Controllers\ReconciliationController;
use App\Http\Controllers\RecurringRuleController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TransactionImportController;
use App\Http\Controllers\TransferController;
use App\Http\Middleware\ResolveLedgerContext;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Fortify\Features;
use Laravel\Jetstream\Jetstream;

Route::match(['get', 'post'], '/auth/ecosystem', [EcosystemAuthController::class, 'handle'])
    ->withoutMiddleware(['Illuminate\Foundation\Http\Middleware\PreventRequestForgery', 'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken', 'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken'])->middleware('throttle:10,1')->name('ecosystem.auth');
Route::get('/', function () {
    return view('welcome');
});

// Cookie Policy — Jetstream's termsAndPrivacyPolicy feature covers terms.show/policy.show
// natively (registered at /terms-of-service and /privacy-policy, reading resources/markdown/
// terms.md and policy.md). There's no Jetstream equivalent for a Cookie Policy, so this one is
// wired by hand, following the exact same Markdown-source convention.
Route::get('/cookies', function () {
    return view('cookies', [
        'cookies' => Str::markdown(file_get_contents(Jetstream::localizedMarkdownPath('cookies.md'))),
    ]);
})->name('cookies');

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
    ResolveLedgerContext::class,
])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // Which ledger is active: the person's own, or one of their companies. Validated against
    // what they belong to (see LedgerContextManager); the active one is kept in the session.
    Route::post('/ledger/context', [LedgerContextController::class, 'update'])->name('ledger.context.switch');

    // Finance domain: every route below is bound to a model by id (route
    // model binding) and, for the show/edit/update/destroy actions, gated
    // through the matching Policy so one user can never load, edit, or
    // delete another ledger's accounts/transactions/categories/budgets by
    // guessing/incrementing the id (IDOR). Every model carries the shared
    // ledger scope (personal or company, see LedgerContextScope), so index,
    // store and route-model binding are all confined to the active ledger.
    Route::get('/insights', [InsightController::class, 'index'])->name('insights.index');
    Route::get('/reports/cash-flow', CashFlowController::class)->name('reports.cash-flow');
    Route::get('/forecast', ForecastController::class)->name('forecast.index');

    // Must be registered before the transactions resource route below --
    // otherwise "import" is swallowed by {transaction}'s route-model binding.
    // The upload is staged on the server and identified by a token, so the
    // preview and the final import never trust (or have to re-post) the rows.
    Route::get('/transactions/import', [TransactionImportController::class, 'create'])->name('transactions.import.create');
    Route::post('/transactions/import/upload', [TransactionImportController::class, 'upload'])->name('transactions.import.upload');
    Route::get('/transactions/import/{token}', [TransactionImportController::class, 'preview'])->where('token', '[0-9a-f-]{36}')->name('transactions.import.preview');
    Route::post('/transactions/import', [TransactionImportController::class, 'store'])->name('transactions.import.store');

    Route::get('/transfers/create', [TransferController::class, 'create'])->name('transfers.create');
    Route::post('/transfers', [TransferController::class, 'store'])->name('transfers.store');
    Route::get('/reconciliation', [ReconciliationController::class, 'index'])->name('reconciliation.index');

    Route::resource('transactions', TransactionController::class)->except(['show']);
    Route::get('/accounts/{account}/delete', [AccountController::class, 'confirmDelete'])->name('accounts.confirm-delete');
    Route::post('/accounts/{account}/opening-balance', [AccountController::class, 'openingBalance'])->name('accounts.opening-balance');
    Route::resource('accounts', AccountController::class)->only(['index', 'store', 'edit', 'update', 'destroy']);
    Route::resource('categories', CategoryController::class)->only(['index', 'store', 'edit', 'update', 'destroy']);
    Route::resource('budgets', BudgetController::class)->only(['index', 'store', 'edit', 'update', 'destroy']);

    Route::get('/recurring', [RecurringRuleController::class, 'index'])->name('recurring.index');
    Route::get('/recurring/{rule}/edit', [RecurringRuleController::class, 'edit'])->name('recurring.edit');
    Route::put('/recurring/{rule}', [RecurringRuleController::class, 'update'])->name('recurring.update');
    Route::post('/recurring/{rule}/pause', [RecurringRuleController::class, 'pause'])->name('recurring.pause');
    Route::post('/recurring/{rule}/resume', [RecurringRuleController::class, 'resume'])->name('recurring.resume');
    Route::post('/recurring/{rule}/stop', [RecurringRuleController::class, 'stop'])->name('recurring.stop');

    // Read-only on purpose: the audit log has no create, update or delete route.
    Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');
});

// Public sign-up is closed (accounts are created by InfoDot at first sign-in). Keep the named route so any link or
// invitation email that points at it lands on the login page instead of failing.
if (! Features::enabled(Features::registration())) {
    Route::redirect('/register', '/login')->name('register');
}
