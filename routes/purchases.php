<?php

use App\Http\Controllers\Purchases\BillController;
use App\Http\Controllers\Purchases\ClaimController;
use App\Http\Controllers\Purchases\DocumentController;
use App\Http\Controllers\Purchases\PayablesController;
use App\Http\Controllers\Purchases\PaymentRunController;
use App\Http\Controllers\Purchases\ReceiptController;
use App\Http\Controllers\Purchases\RecurringBillController;
use App\Http\Controllers\Purchases\SupplierController;
use App\Http\Controllers\Purchases\SupplierPaymentController;
use App\Http\Middleware\ResolveLedgerContext;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Purchases: suppliers, bills, expense claims, receipts, payments, payment runs
|--------------------------------------------------------------------------
| Required from routes/web.php. This file registers routes only: no aliases, limiters, gates, morph maps or bindings
| (production runs route:cache; those live in PurchasesServiceProvider). Every {id} is bound inside the active ledger by the
| shared ledger scope, so another ledger's id is a 404 before any policy runs.
*/

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
    ResolveLedgerContext::class,
])->prefix('purchases')->name('purchases.')->group(function () {
    Route::get('/', [PayablesController::class, 'overview'])->name('index');

    // bills and supplier credit notes
    Route::get('/bills', [BillController::class, 'index'])->name('bills.index');
    Route::get('/bills/create', [BillController::class, 'create'])->name('bills.create');
    Route::post('/bills', [BillController::class, 'store'])->name('bills.store');
    Route::get('/bills/{bill}', [BillController::class, 'show'])->name('bills.show');
    Route::get('/bills/{bill}/edit', [BillController::class, 'edit'])->name('bills.edit');
    Route::put('/bills/{bill}', [BillController::class, 'update'])->name('bills.update');
    Route::delete('/bills/{bill}', [BillController::class, 'destroy'])->name('bills.destroy');
    Route::post('/bills/{bill}/submit', [BillController::class, 'submit'])->name('bills.submit');
    Route::post('/bills/{bill}/post', [BillController::class, 'post'])->name('bills.post');
    Route::post('/bills/{bill}/withdraw', [BillController::class, 'withdraw'])->name('bills.withdraw');
    Route::post('/bills/{bill}/void', [BillController::class, 'void'])->name('bills.void');
    Route::post('/bills/{bill}/attachments', [BillController::class, 'attach'])->name('bills.attachments.store');
    Route::get('/bills/{bill}/credit-note', [BillController::class, 'createCredit'])->name('bills.credit.create');
    Route::post('/bills/{bill}/credit-note', [BillController::class, 'storeCredit'])->name('bills.credit.store');
    Route::post('/bills/{bill}/apply-credit', [BillController::class, 'applyCredit'])->name('bills.credit.apply');

    // suppliers
    Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
    Route::get('/suppliers/create', [SupplierController::class, 'create'])->name('suppliers.create');
    Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
    Route::get('/suppliers/{party}', [SupplierController::class, 'show'])->name('suppliers.show')->withTrashed();
    Route::get('/suppliers/{party}/edit', [SupplierController::class, 'edit'])->name('suppliers.edit');
    Route::put('/suppliers/{party}', [SupplierController::class, 'update'])->name('suppliers.update');
    Route::get('/suppliers/{party}/statement', [SupplierController::class, 'statement'])->name('suppliers.statement');
    Route::get('/suppliers/{party}/bank', [SupplierController::class, 'editBank'])->name('suppliers.bank.edit');
    Route::post('/suppliers/{party}/bank', [SupplierController::class, 'storeBank'])->name('suppliers.bank.store');
    Route::post('/bank-changes/{detail}/approve', [SupplierController::class, 'approveBank'])->name('bank.approve');
    Route::post('/bank-changes/{detail}/reject', [SupplierController::class, 'rejectBank'])->name('bank.reject');

    // supplier payments and payment runs
    Route::get('/payments', [SupplierPaymentController::class, 'index'])->name('payments.index');
    Route::get('/payments/create', [SupplierPaymentController::class, 'create'])->name('payments.create');
    Route::post('/payments', [SupplierPaymentController::class, 'store'])->name('payments.store');
    Route::get('/payments/{payment}', [SupplierPaymentController::class, 'show'])->name('payments.show');
    Route::post('/payments/{payment}/reverse', [SupplierPaymentController::class, 'reverse'])->name('payments.reverse');
    Route::post('/payments/{payment}/apply', [SupplierPaymentController::class, 'apply'])->name('payments.apply');

    Route::get('/runs', [PaymentRunController::class, 'index'])->name('runs.index');
    Route::get('/runs/create', [PaymentRunController::class, 'create'])->name('runs.create');
    Route::post('/runs', [PaymentRunController::class, 'store'])->name('runs.store');
    Route::get('/runs/{run}', [PaymentRunController::class, 'show'])->name('runs.show');
    Route::post('/runs/{run}/submit', [PaymentRunController::class, 'submit'])->name('runs.submit');
    Route::post('/runs/{run}/execute', [PaymentRunController::class, 'execute'])->name('runs.execute');
    Route::post('/runs/{run}/cancel', [PaymentRunController::class, 'cancel'])->name('runs.cancel');
    Route::get('/runs/{run}/csv', [PaymentRunController::class, 'csv'])->name('runs.csv');

    // expense claims
    Route::get('/claims', [ClaimController::class, 'index'])->name('claims.index');
    Route::get('/claims/create', [ClaimController::class, 'create'])->name('claims.create');
    Route::post('/claims', [ClaimController::class, 'store'])->name('claims.store');
    Route::get('/claims/{claim}', [ClaimController::class, 'show'])->name('claims.show');
    Route::get('/claims/{claim}/edit', [ClaimController::class, 'edit'])->name('claims.edit');
    Route::put('/claims/{claim}', [ClaimController::class, 'update'])->name('claims.update');
    Route::delete('/claims/{claim}', [ClaimController::class, 'destroy'])->name('claims.destroy');
    Route::post('/claims/{claim}/submit', [ClaimController::class, 'submit'])->name('claims.submit');
    Route::post('/claims/{claim}/post', [ClaimController::class, 'post'])->name('claims.post');
    Route::post('/claims/{claim}/withdraw', [ClaimController::class, 'withdraw'])->name('claims.withdraw');
    Route::post('/claims/{claim}/void', [ClaimController::class, 'void'])->name('claims.void');
    Route::post('/claims/{claim}/lines/{claimLine}/attachments', [ClaimController::class, 'attach'])->name('claims.lines.attachments.store');

    // receipt and invoice capture (the inbox)
    Route::get('/receipts', [ReceiptController::class, 'index'])->name('receipts.index');
    Route::post('/receipts', [ReceiptController::class, 'store'])->name('receipts.store');
    Route::get('/receipts/{attachment}', [ReceiptController::class, 'show'])->name('receipts.show');
    Route::post('/receipts/{attachment}/read', [ReceiptController::class, 'read'])->name('receipts.read');
    Route::post('/receipts/{attachment}/bill', [ReceiptController::class, 'toBill'])->name('receipts.bill');
    Route::post('/receipts/{attachment}/claim', [ReceiptController::class, 'toClaim'])->name('receipts.claim');
    Route::post('/receipts/{attachment}/reject', [ReceiptController::class, 'reject'])->name('receipts.reject');

    // documents that are missing
    Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::post('/documents/transactions/{transaction}', [DocumentController::class, 'attachToTransaction'])->name('documents.transactions.store');

    // payables, ageing, settings
    Route::get('/ap', [PayablesController::class, 'ap'])->name('ap.index');
    Route::get('/settings', [PayablesController::class, 'editSettings'])->name('settings.edit');
    Route::put('/settings', [PayablesController::class, 'updateSettings'])->name('settings.update');

    // recurring bills
    Route::get('/recurring', [RecurringBillController::class, 'index'])->name('recurring.index');
    Route::get('/recurring/create', [RecurringBillController::class, 'create'])->name('recurring.create');
    Route::post('/recurring', [RecurringBillController::class, 'store'])->name('recurring.store');
    Route::post('/recurring/{recurring}/pause', [RecurringBillController::class, 'pause'])->name('recurring.pause');
    Route::post('/recurring/{recurring}/resume', [RecurringBillController::class, 'resume'])->name('recurring.resume');
    Route::post('/recurring/{recurring}/end', [RecurringBillController::class, 'end'])->name('recurring.end');
});
