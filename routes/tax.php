<?php

use App\Http\Controllers\Tax\CalendarController;
use App\Http\Controllers\Tax\DocumentController;
use App\Http\Controllers\Tax\Emp201Controller;
use App\Http\Controllers\Tax\ExplainController;
use App\Http\Controllers\Tax\IncomeTaxController;
use App\Http\Controllers\Tax\OverviewController;
use App\Http\Controllers\Tax\PayrollController;
use App\Http\Controllers\Tax\PayrollImportController;
use App\Http\Controllers\Tax\ProfileController;
use App\Http\Controllers\Tax\ProvisionalController;
use App\Http\Controllers\Tax\ReturnController;
use App\Http\Controllers\Tax\VatController;
use App\Http\Middleware\ResolveLedgerContext;
use Illuminate\Support\Facades\Route;

/*
 * Tax (R-44). Registers nothing but routes (production runs route:cache). Reading a tax page needs membership of the ledger;
 * every change asks LedgerAccess for the capability that matches it (prepare-tax-return, record-tax-submission, manage-tax,
 * view-payroll-detail, export-reports). Route-model binding runs inside the ledger scope, so another ledger's id is a 404
 * before any capability check.
 */
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified', ResolveLedgerContext::class])
    ->prefix('tax')->name('tax.')->group(function () {
        Route::get('/', OverviewController::class)->name('index');

        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::get('/obligations', [ProfileController::class, 'obligations'])->name('obligations');

        Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');
        Route::post('/calendar/generate', [CalendarController::class, 'generate'])->name('calendar.generate');
        Route::get('/calendar/{taxDeadline}', [CalendarController::class, 'show'])->where('taxDeadline', '[0-9]{1,18}')->name('calendar.show');
        Route::post('/calendar/{taxDeadline}/tick', [CalendarController::class, 'tick'])->where('taxDeadline', '[0-9]{1,18}')->name('calendar.tick');
        Route::post('/calendar/{taxDeadline}/waive', [CalendarController::class, 'waive'])->where('taxDeadline', '[0-9]{1,18}')->name('calendar.waive');

        Route::get('/vat', [VatController::class, 'index'])->name('vat.index');
        Route::post('/vat/start', [VatController::class, 'start'])->name('vat.start');
        Route::get('/vat/review', [VatController::class, 'review'])->name('vat.review');
        Route::post('/vat/review/resolve', [VatController::class, 'resolve'])->name('vat.review.resolve');

        Route::get('/returns/{taxReturn}', [ReturnController::class, 'show'])->where('taxReturn', '[0-9]{1,18}')->name('returns.show');
        Route::post('/returns/{taxReturn}/adjustments', [ReturnController::class, 'adjust'])->where('taxReturn', '[0-9]{1,18}')->name('returns.adjust');
        Route::post('/returns/{taxReturn}/adjustments/{returnAdjustment}/void', [ReturnController::class, 'voidAdjustment'])->where(['taxReturn' => '[0-9]{1,18}', 'returnAdjustment' => '[0-9]{1,18}'])->name('returns.adjust.void');
        Route::post('/returns/{taxReturn}/prepare', [ReturnController::class, 'prepare'])->where('taxReturn', '[0-9]{1,18}')->name('returns.prepare');
        Route::post('/returns/{taxReturn}/withdraw', [ReturnController::class, 'withdraw'])->where('taxReturn', '[0-9]{1,18}')->name('returns.withdraw');
        Route::post('/returns/{taxReturn}/submission', [ReturnController::class, 'submission'])->where('taxReturn', '[0-9]{1,18}')->name('returns.submission');
        Route::post('/returns/{taxReturn}/payment', [ReturnController::class, 'payment'])->where('taxReturn', '[0-9]{1,18}')->name('returns.payment');
        Route::post('/returns/{taxReturn}/revision', [ReturnController::class, 'revision'])->where('taxReturn', '[0-9]{1,18}')->name('returns.revision');
        Route::post('/returns/{taxReturn}/settlement', [ReturnController::class, 'settlement'])->where('taxReturn', '[0-9]{1,18}')->name('returns.settlement');
        Route::get('/returns/{taxReturn}/workpaper', [ReturnController::class, 'workpaper'])->where('taxReturn', '[0-9]{1,18}')->name('returns.workpaper');
        Route::get('/returns/{taxReturn}/workpaper.csv', [ReturnController::class, 'csv'])->where('taxReturn', '[0-9]{1,18}')->name('returns.csv');

        Route::get('/emp201', [Emp201Controller::class, 'index'])->name('emp201.index');
        Route::post('/emp201/start', [Emp201Controller::class, 'start'])->name('emp201.start');

        Route::get('/payroll', [PayrollController::class, 'index'])->name('payroll.index');
        Route::post('/payroll', [PayrollController::class, 'store'])->name('payroll.store');
        Route::get('/payroll/{taxPayrollRun}', [PayrollController::class, 'show'])->where('taxPayrollRun', '[0-9]{1,18}')->name('payroll.show');
        Route::post('/payroll/{taxPayrollRun}/post', [PayrollController::class, 'post'])->where('taxPayrollRun', '[0-9]{1,18}')->name('payroll.post');

        Route::get('/income', [IncomeTaxController::class, 'index'])->name('income.index');
        Route::post('/income/adjustments', [IncomeTaxController::class, 'adjust'])->name('income.adjust');
        Route::post('/income/adjustments/{taxAdjustment}/void', [IncomeTaxController::class, 'voidAdjustment'])->where('taxAdjustment', '[0-9]{1,18}')->name('income.adjust.void');
        Route::post('/income/estimates', [IncomeTaxController::class, 'save'])->name('income.save');
        Route::post('/income/provision', [IncomeTaxController::class, 'provision'])->name('income.provision');
        Route::get('/income/workpaper', [IncomeTaxController::class, 'workpaper'])->name('income.workpaper');
        Route::get('/income/workpaper.csv', [IncomeTaxController::class, 'csv'])->name('income.csv');

        Route::get('/provisional', [ProvisionalController::class, 'index'])->name('provisional.index');
        Route::post('/provisional/estimate', [ProvisionalController::class, 'estimate'])->name('provisional.estimate');
        Route::post('/provisional/{provisionalPayment}/payment', [ProvisionalController::class, 'payment'])->where('provisionalPayment', '[0-9]{1,18}')->name('provisional.payment');

        Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
        Route::post('/documents', [DocumentController::class, 'store'])->name('documents.store');
        Route::post('/documents/{taxDocument}/hold', [DocumentController::class, 'hold'])->where('taxDocument', '[0-9]{1,18}')->name('documents.hold');
        Route::post('/documents/{taxDocument}/archive', [DocumentController::class, 'archive'])->where('taxDocument', '[0-9]{1,18}')->name('documents.archive');
        Route::get('/documents/{taxDocument}/file', [DocumentController::class, 'file'])->where('taxDocument', '[0-9]{1,18}')->name('documents.file');
        Route::post('/correspondence', [DocumentController::class, 'correspond'])->name('correspondence.store');
        Route::post('/correspondence/{taxCorrespondence}/status', [DocumentController::class, 'correspondenceStatus'])->where('taxCorrespondence', '[0-9]{1,18}')->name('correspondence.status');

        Route::get('/explain', [ExplainController::class, 'index'])->name('explain.index');
        Route::get('/accountant-pack', [ExplainController::class, 'pack'])->name('pack.index');
        Route::post('/accountant-pack', [ExplainController::class, 'download'])->name('pack.download');
    });

// Payroll tax summary import (Dot.HR contract): signed by the sender, no session, no CSRF token. Throttled because anyone can call it.
Route::post('/api/tax/payroll-import', PayrollImportController::class)
    ->withoutMiddleware(['Illuminate\Foundation\Http\Middleware\PreventRequestForgery', 'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken', 'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken'])
    ->middleware('throttle:30,1')
    ->name('tax.payroll-import');
