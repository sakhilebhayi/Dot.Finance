<?php

use App\Http\Controllers\Sales\CalculationController;
use App\Http\Controllers\Sales\CustomerController;
use App\Http\Controllers\Sales\PaymentController;
use App\Http\Controllers\Sales\ProfileController;
use App\Http\Controllers\Sales\ReceivablesController;
use App\Http\Controllers\Sales\RecurringController;
use App\Http\Controllers\Sales\ReminderController;
use App\Http\Controllers\Sales\SalesDocumentController;
use App\Http\Controllers\Sales\ShareController;
use App\Http\Controllers\Sales\ShareLinkController;
use App\Http\Middleware\ResolveLedgerContext;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
|--------------------------------------------------------------------------
| Sales: quotes, invoices, credit notes, payments received, receivables
|--------------------------------------------------------------------------
| Required from routes/web.php. Registers routes only: no aliases, limiters, gates or bindings (production runs
| route:cache; the throttle and the policy live in SalesServiceProvider).
*/

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified', ResolveLedgerContext::class])->prefix('sales')->group(function () {
    // lists
    Route::get('/invoices', [SalesDocumentController::class, 'index'])->defaults('group', 'invoices')->name('sales.invoices.index');
    Route::get('/quotes', [SalesDocumentController::class, 'index'])->defaults('group', 'quotes')->name('sales.quotes.index');
    Route::get('/credit-notes', [SalesDocumentController::class, 'index'])->defaults('group', 'credit-notes')->name('sales.credit-notes.index');

    // documents
    Route::get('/invoices/create', [SalesDocumentController::class, 'create'])->defaults('kind', 'tax_invoice')->name('sales.invoices.create');
    Route::get('/quotes/create', [SalesDocumentController::class, 'create'])->defaults('kind', 'quote')->name('sales.quotes.create');
    Route::post('/calculate', CalculationController::class)->middleware('throttle:120,1')->name('sales.calculate');
    Route::post('/documents', [SalesDocumentController::class, 'store'])->name('sales.documents.store');
    Route::get('/documents/{document}', [SalesDocumentController::class, 'show'])->name('sales.documents.show');
    Route::get('/documents/{document}/edit', [SalesDocumentController::class, 'edit'])->name('sales.documents.edit');
    Route::put('/documents/{document}', [SalesDocumentController::class, 'update'])->name('sales.documents.update');
    Route::delete('/documents/{document}', [SalesDocumentController::class, 'discard'])->name('sales.documents.discard');
    Route::post('/documents/{document}/issue', [SalesDocumentController::class, 'issue'])->name('sales.documents.issue');
    Route::post('/documents/{document}/void', [SalesDocumentController::class, 'void'])->name('sales.documents.void');
    Route::post('/documents/{document}/convert', [SalesDocumentController::class, 'convert'])->name('sales.documents.convert');
    Route::post('/documents/{document}/answer', [SalesDocumentController::class, 'answer'])->name('sales.documents.answer');
    Route::post('/documents/{document}/credit-note', [SalesDocumentController::class, 'credit'])->name('sales.documents.credit');
    Route::post('/documents/{document}/debit-note', [SalesDocumentController::class, 'debit'])->name('sales.documents.debit');
    Route::post('/documents/{document}/send', [SalesDocumentController::class, 'send'])->name('sales.documents.send');
    Route::post('/documents/{document}/share', [SalesDocumentController::class, 'share'])->name('sales.documents.share');
    Route::post('/documents/{document}/apply-credit', [SalesDocumentController::class, 'applyCredit'])->name('sales.documents.apply-credit');
    Route::post('/documents/{document}/write-off', [SalesDocumentController::class, 'writeOff'])->name('sales.documents.write-off');
    Route::get('/documents/{document}/print', [SalesDocumentController::class, 'print'])->name('sales.documents.print');
    Route::get('/documents/{document}/pdf', [SalesDocumentController::class, 'pdf'])->name('sales.documents.pdf');
    Route::post('/links/{link}/revoke', [ShareLinkController::class, 'revoke'])->name('sales.links.revoke');

    // payments received
    Route::get('/payments', [PaymentController::class, 'index'])->name('sales.payments.index');
    Route::get('/payments/create', [PaymentController::class, 'create'])->name('sales.payments.create');
    Route::post('/payments', [PaymentController::class, 'store'])->middleware('throttle:60,1')->name('sales.payments.store');
    Route::get('/payments/{payment}', [PaymentController::class, 'show'])->name('sales.payments.show');
    Route::post('/payments/{payment}/allocate', [PaymentController::class, 'allocate'])->name('sales.payments.allocate');
    Route::post('/payments/{payment}/reverse', [PaymentController::class, 'reverse'])->name('sales.payments.reverse');
    Route::post('/applications/{application}/release', [PaymentController::class, 'release'])->name('sales.applications.release');
    Route::post('/refunds', [PaymentController::class, 'refund'])->name('sales.refunds.store');

    // receivables and customers
    Route::get('/receivables', [ReceivablesController::class, 'index'])->name('sales.receivables.index');
    Route::get('/receivables.csv', [ReceivablesController::class, 'csv'])->name('sales.receivables.csv');
    Route::get('/customers', [CustomerController::class, 'index'])->name('sales.customers.index');
    Route::get('/customers/{party}', [CustomerController::class, 'show'])->name('sales.customers.show');
    Route::post('/customers/{party}/reminders', [CustomerController::class, 'reminders'])->name('sales.customers.reminders');
    Route::post('/customers/{party}/link-transaction', [CustomerController::class, 'linkTransaction'])->name('sales.customers.link-transaction');
    Route::get('/customers/{party}/statement', [CustomerController::class, 'statement'])->name('sales.customers.statement');
    Route::get('/customers/{party}/statement.csv', [CustomerController::class, 'statementCsv'])->name('sales.customers.statement.csv');
    Route::get('/customers/{party}/statement.pdf', [CustomerController::class, 'statementPdf'])->name('sales.customers.statement.pdf');
    Route::post('/customers/{party}/statement/share', [CustomerController::class, 'statementShare'])->name('sales.customers.statement.share');
    Route::get('/reminders', [ReminderController::class, 'index'])->name('sales.reminders.index');

    // repeating invoices
    Route::get('/recurring', [RecurringController::class, 'index'])->name('sales.recurring.index');
    Route::get('/recurring/create', [RecurringController::class, 'create'])->name('sales.recurring.create');
    Route::post('/recurring', [RecurringController::class, 'store'])->name('sales.recurring.store');
    Route::post('/recurring/run', [RecurringController::class, 'run'])->name('sales.recurring.run');
    Route::get('/recurring/{template}/edit', [RecurringController::class, 'edit'])->name('sales.recurring.edit');
    Route::put('/recurring/{template}', [RecurringController::class, 'update'])->name('sales.recurring.update');
    Route::post('/recurring/{template}/status', [RecurringController::class, 'toggle'])->name('sales.recurring.status');

    // business profile
    Route::get('/profile', [ProfileController::class, 'edit'])->name('sales.profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('sales.profile.update');
    Route::post('/profile/logo', [ProfileController::class, 'logo'])->name('sales.profile.logo');
    Route::get('/profile/logo', [ProfileController::class, 'showLogo'])->name('sales.profile.logo.show');
});

// What a customer opens from a link they were sent. No sign-in, no cookie, throttled, 404 when unknown, expired or revoked.
// The session, cookie and CSRF middleware are taken off these two routes: a customer opening an invoice gets no session and no cookie
// (they were being set; QA security D-1408). Nothing on the public page reads a session.
Route::middleware('throttle:sales-share')->withoutMiddleware([
    StartSession::class,
    ShareErrorsFromSession::class,
    AddQueuedCookiesToResponse::class,
    PreventRequestForgery::class,
])->group(function () {
    Route::get('/s/{shareToken}', [ShareController::class, 'show'])->name('sales.share.show');
    Route::get('/s/{shareToken}/pdf', [ShareController::class, 'pdf'])->name('sales.share.pdf');
});
