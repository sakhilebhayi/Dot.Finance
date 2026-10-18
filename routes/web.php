<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InsightController;
use App\Http\Controllers\ReconciliationController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TransactionImportController;
use App\Http\Controllers\TransferController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Jetstream\Jetstream;

Route::get('/auth/ecosystem', [EcosystemAuthController::class, 'handle'])->middleware('throttle:10,1')->name('ecosystem.auth');
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
])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // Finance domain: every route below is bound to a model by id (route
    // model binding) and, for the show/edit/update/destroy actions, gated
    // through the matching Policy so one user can never load, edit, or
    // delete another user's accounts/transactions/categories/budgets by
    // guessing/incrementing the id (IDOR). Index/store are scoped to
    // auth()->id() directly since there's no team-scoping in this app.
    Route::get('/insights', [InsightController::class, 'index'])->name('insights.index');

    // Must be registered before the transactions resource route below --
    // otherwise "import" is swallowed by {transaction}'s route-model binding.
    Route::get('/transactions/import', [TransactionImportController::class, 'create'])->name('transactions.import.create');
    Route::post('/transactions/import/preview', [TransactionImportController::class, 'preview'])->name('transactions.import.preview');
    Route::post('/transactions/import', [TransactionImportController::class, 'store'])->name('transactions.import.store');

    Route::get('/transfers/create', [TransferController::class, 'create'])->name('transfers.create');
    Route::post('/transfers', [TransferController::class, 'store'])->name('transfers.store');
    Route::get('/reconciliation', [ReconciliationController::class, 'index'])->name('reconciliation.index');

    Route::resource('transactions', TransactionController::class)->except(['show']);
    Route::resource('accounts', AccountController::class)->only(['index', 'store', 'destroy']);
    Route::resource('categories', CategoryController::class)->only(['index', 'store', 'destroy']);
    Route::resource('budgets', BudgetController::class)->only(['index', 'store', 'destroy']);
});
