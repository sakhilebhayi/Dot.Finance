<?php

use App\Http\Controllers\Reporting\DrillController;
use App\Http\Controllers\Reporting\ExportController;
use App\Http\Controllers\Reporting\PresetController;
use App\Http\Controllers\Reporting\ReportController;
use App\Http\Middleware\EnsureCompanyEntitled;
use App\Http\Middleware\ResolveLedgerContext;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Reporting (R-44 wave 2)
|--------------------------------------------------------------------------
| Required from routes/web.php. This file registers routes only: no aliases, limiters, gates or bindings
| (production runs route:cache; those live in ReportingServiceProvider).
|
| Every report is one GET page; the report key is a route default, never a request value. Route-model binding goes
| through the ledger scope, so another ledger's export or saved view is a 404 before any capability check.
*/
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified', ResolveLedgerContext::class, EnsureCompanyEntitled::class])
    ->prefix('reports')->name('reports.')->group(function () {
        Route::get('/', [ReportController::class, 'index'])->name('index');

        foreach (['profit-loss', 'balance-sheet', 'cash-flow-statement', 'trial-balance', 'general-ledger', 'ageing-receivables', 'ageing-payables', 'vat', 'tax-summary', 'revenue', 'expenses', 'performance', 'budget-vs-actual'] as $key) {
            Route::get('/'.$key, [ReportController::class, 'show'])->defaults('report', $key)->name($key);
        }

        Route::get('/drill', DrillController::class)->name('drill');

        Route::get('/exports', [ExportController::class, 'index'])->name('exports.index');
        Route::post('/exports', [ExportController::class, 'store'])->name('exports.store');
        Route::get('/exports/{reportExport}', [ExportController::class, 'show'])->where('reportExport', '[0-9]{1,18}')->name('exports.show');
        // Opened through a temporary signed link (the status page mints it) AND the person's access. A missing or wrong
        // signature is a 403; another ledger's export is a 404 before that.
        Route::get('/exports/{reportExport}/download', [ExportController::class, 'download'])->where('reportExport', '[0-9]{1,18}')->name('exports.download')->middleware('signed');

        Route::post('/presets', [PresetController::class, 'store'])->name('presets.store');
        Route::delete('/presets/{reportPreset}', [PresetController::class, 'destroy'])->where('reportPreset', '[0-9]{1,18}')->name('presets.destroy');
    });
