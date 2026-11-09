<?php

use App\Http\Controllers\Bank\ReconciliationActionController;
use App\Http\Controllers\Bank\ReconciliationController;
use App\Http\Controllers\Bank\RuleController;
use App\Http\Controllers\Bank\StatementController;
use App\Http\Controllers\Bank\StatementImportController;
use App\Http\Middleware\ResolveLedgerContext;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Bank: statement import and reconciliation (R-44 Bank)
|--------------------------------------------------------------------------
| Required from routes/web.php. This file registers routes only: no aliases, limiters, gates or bindings (production runs
| route:cache; those live in BankServiceProvider).
|
| Every id in a path is bound through the ledger scope, so another ledger's statement, line, reconciliation, match or rule is
| a 404 before any capability check. Reading needs membership (view-finance); every write needs run-reconciliation, and
| reopening a reconciliation or accepting a difference additionally needs the accountant-level post-journal capability, which
| the service checks again.
*/

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
    ResolveLedgerContext::class,
])->prefix('bank')->name('bank.')->group(function () {
    // Statements
    Route::get('/statements', [StatementController::class, 'index'])->name('statements.index');
    Route::get('/statements/import', [StatementImportController::class, 'create'])->name('import.create');
    Route::post('/statements/import', [StatementImportController::class, 'upload'])->name('import.upload')->middleware('throttle:20,1');
    Route::get('/statements/import/{token}', [StatementImportController::class, 'preview'])->where('token', '[0-9a-f-]{36}')->name('import.preview');
    Route::post('/statements/import/{token}', [StatementImportController::class, 'store'])->where('token', '[0-9a-f-]{36}')->name('import.store')->middleware('throttle:20,1');
    Route::get('/statements/{statement}', [StatementController::class, 'show'])->where('statement', '[0-9]{1,18}')->name('statements.show');
    Route::delete('/statements/{statement}', [StatementController::class, 'destroy'])->where('statement', '[0-9]{1,18}')->name('statements.destroy');

    // Reconciliation sessions
    Route::get('/reconciliation', [ReconciliationController::class, 'index'])->name('reconciliation.index');
    Route::post('/reconciliation', [ReconciliationController::class, 'start'])->name('reconciliation.start');
    Route::get('/reconciliation/{reconciliation}', [ReconciliationController::class, 'show'])->where('reconciliation', '[0-9]{1,18}')->name('reconciliation.show');
    Route::get('/reconciliation/{reconciliation}/report', [ReconciliationController::class, 'report'])->where('reconciliation', '[0-9]{1,18}')->name('reconciliation.report');
    Route::get('/reconciliation/{reconciliation}/report.csv', [ReconciliationController::class, 'csv'])->where('reconciliation', '[0-9]{1,18}')->name('reconciliation.csv');

    // What a person does inside a session (all POST, all re-checked on the server)
    Route::prefix('/reconciliation/{reconciliation}')->where(['reconciliation' => '[0-9]{1,18}'])->name('reconciliation.')->group(function () {
        Route::post('/accept', [ReconciliationActionController::class, 'accept'])->name('accept');
        Route::post('/match', [ReconciliationActionController::class, 'match'])->name('match');
        Route::post('/matches/{bankMatch}/release', [ReconciliationActionController::class, 'unmatch'])->where('bankMatch', '[0-9]{1,18}')->name('unmatch');
        Route::post('/entries', [ReconciliationActionController::class, 'entries'])->name('entries');
        Route::post('/transfer', [ReconciliationActionController::class, 'transfer'])->name('transfer');
        Route::post('/settle', [ReconciliationActionController::class, 'settle'])->name('settle');
        Route::post('/difference', [ReconciliationActionController::class, 'difference'])->name('difference');
        Route::post('/complete', [ReconciliationActionController::class, 'complete'])->name('complete');
        Route::post('/reopen', [ReconciliationActionController::class, 'reopen'])->name('reopen');
    });

    // Bank rules (suggestions only)
    Route::get('/rules', [RuleController::class, 'index'])->name('rules.index');
    Route::post('/rules', [RuleController::class, 'store'])->name('rules.store');
    Route::get('/rules/{bankRule}/edit', [RuleController::class, 'edit'])->where('bankRule', '[0-9]{1,18}')->name('rules.edit');
    Route::put('/rules/{bankRule}', [RuleController::class, 'update'])->where('bankRule', '[0-9]{1,18}')->name('rules.update');
    Route::delete('/rules/{bankRule}', [RuleController::class, 'destroy'])->where('bankRule', '[0-9]{1,18}')->name('rules.destroy');
});
