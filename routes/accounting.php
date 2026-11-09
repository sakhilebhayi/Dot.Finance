<?php

use App\Http\Controllers\Accounting\ChartController;
use App\Http\Controllers\Accounting\GeneralLedgerController;
use App\Http\Controllers\Accounting\JournalController;
use App\Http\Controllers\Accounting\PeriodController;
use App\Http\Controllers\Accounting\SetupController;
use App\Http\Controllers\Accounting\TrialBalanceController;
use Illuminate\Support\Facades\Route;

/*
 * Accounting (R-44 F1). Required from routes/web.php inside the authenticated, ledger-resolved group, so every
 * route here already runs behind auth, verified e-mail and ResolveLedgerContext. Nothing is registered from this
 * file but routes (aliases, gates and bindings live in providers: production runs route:cache).
 *
 * Route-model binding goes through the ledger scope, so another ledger's id is a 404 before any capability
 * check; the controllers then ask LedgerAccess what the signed-in person may do.
 */
Route::prefix('accounting')->name('accounting.')->group(function () {
    // Enable-accounting wizard
    Route::get('/setup', [SetupController::class, 'show'])->name('setup');
    Route::post('/setup', [SetupController::class, 'prepare'])->name('setup.prepare');
    Route::get('/setup/review', [SetupController::class, 'review'])->name('setup.review');
    Route::post('/setup/mapping', [SetupController::class, 'mapping'])->name('setup.mapping');
    Route::post('/setup/dry-run', [SetupController::class, 'dryRun'])->name('setup.dry-run');
    Route::post('/setup/apply', [SetupController::class, 'apply'])->name('setup.apply');

    // Chart of accounts
    Route::get('/chart', [ChartController::class, 'index'])->name('chart.index');
    Route::get('/chart/create', [ChartController::class, 'create'])->name('chart.create');
    Route::post('/chart', [ChartController::class, 'store'])->name('chart.store');
    Route::get('/chart/{glAccount}/edit', [ChartController::class, 'edit'])->name('chart.edit');
    Route::put('/chart/{glAccount}', [ChartController::class, 'update'])->name('chart.update');
    Route::post('/chart/{glAccount}/deactivate', [ChartController::class, 'deactivate'])->name('chart.deactivate');
    Route::post('/chart/{glAccount}/activate', [ChartController::class, 'activate'])->name('chart.activate');

    // Journals (append-only: there is no edit or delete route, only reverse)
    Route::get('/journals', [JournalController::class, 'index'])->name('journals.index');
    Route::get('/journals/create', [JournalController::class, 'create'])->name('journals.create');
    Route::post('/journals', [JournalController::class, 'store'])->name('journals.store');
    Route::get('/journals/{entry}', [JournalController::class, 'show'])->where('entry', '[0-9]{1,18}')->name('journals.show');
    Route::post('/journals/{entry}/reverse', [JournalController::class, 'reverse'])->where('entry', '[0-9]{1,18}')->name('journals.reverse');

    // Periods
    Route::get('/periods', [PeriodController::class, 'index'])->name('periods.index');
    Route::post('/periods/close-through', [PeriodController::class, 'closeThrough'])->name('periods.close-through');
    Route::post('/periods/{period}/close', [PeriodController::class, 'close'])->name('periods.close');
    Route::post('/periods/{period}/reopen', [PeriodController::class, 'reopen'])->name('periods.reopen');

    // Reports
    Route::get('/trial-balance', TrialBalanceController::class)->name('trial-balance');
    Route::get('/general-ledger', GeneralLedgerController::class)->name('general-ledger');
});
