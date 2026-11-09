<?php

use App\Http\Controllers\Shared\AttachmentController;
use App\Http\Controllers\Shared\PartyAttachmentController;
use App\Http\Controllers\Shared\PartyController;
use App\Http\Middleware\ResolveLedgerContext;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Shared data layer routes (parties, attachments)
|--------------------------------------------------------------------------
| Required from routes/web.php. This file registers routes only: no aliases, limiters, gates or
| bindings (production runs route:cache; those live in SharedDataServiceProvider).
*/

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
    ResolveLedgerContext::class,
])->group(function () {
    Route::get('/parties', [PartyController::class, 'index'])->name('parties.index');
    Route::get('/parties/create', [PartyController::class, 'create'])->name('parties.create');
    Route::post('/parties', [PartyController::class, 'store'])->name('parties.store');
    Route::get('/parties/{party}', [PartyController::class, 'show'])->name('parties.show')->withTrashed();
    Route::get('/parties/{party}/edit', [PartyController::class, 'edit'])->name('parties.edit');
    Route::put('/parties/{party}', [PartyController::class, 'update'])->name('parties.update');
    Route::delete('/parties/{party}', [PartyController::class, 'destroy'])->name('parties.destroy');
    Route::post('/parties/{party}/restore', [PartyController::class, 'restore'])->name('parties.restore')->withTrashed();

    Route::post('/parties/{party}/attachments', [PartyAttachmentController::class, 'store'])->name('parties.attachments.store');

    // A file is opened through a temporary signed URL (Attachments::url) AND the signed-in person's access to the
    // owning record. A missing or wrong signature is a 403; another ledger's file is a 404 before that.
    Route::get('/attachments/{attachment}', [AttachmentController::class, 'show'])->name('attachments.show')->middleware('signed');
});
