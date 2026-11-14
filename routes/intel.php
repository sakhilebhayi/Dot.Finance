<?php

use App\Http\Controllers\Integrations\EmallReviewController;
use App\Http\Controllers\Integrations\IntegrationsController;
use App\Http\Controllers\Intelligence\AssistantController;
use App\Http\Controllers\Intelligence\AttentionController;
use App\Http\Controllers\Intelligence\NotificationController;
use App\Http\Middleware\EnsureCompanyEntitled;
use App\Http\Middleware\ResolveLedgerContext;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Intelligence and integrations: the assistant, what needs attention, notifications, the integration status page
| and the Dot.Emall review screen.
|--------------------------------------------------------------------------
| Registers routes only (route:cache): the rate limiter, the capability gates and every binding live in
| IntelligenceServiceProvider and ControlsServiceProvider. Every route is signed-in and confined to the active
| ledger; each also names the capability it needs.
*/

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified', ResolveLedgerContext::class, EnsureCompanyEntitled::class])->group(function () {
    // The assistant: asking reads only. A draft is confirmed by a POST (CSRF-protected) bound to the draft and the asker.
    Route::middleware('can:view-finance')->prefix('assistant')->name('intel.assistant.')->group(function () {
        Route::get('/', [AssistantController::class, 'index'])->name('index');
        Route::post('/', [AssistantController::class, 'ask'])->middleware('throttle:finance-assistant')->name('ask');
        Route::delete('/messages', [AssistantController::class, 'clear'])->name('clear');
        Route::delete('/messages/{message}', [AssistantController::class, 'destroy'])->where('message', '[0-9]{1,18}')->name('destroy');
        Route::get('/drafts/{draft}', [AssistantController::class, 'draft'])->where('draft', '[0-9]{1,18}')->name('draft');
        Route::post('/drafts/{draft}/confirm', [AssistantController::class, 'confirm'])->where('draft', '[0-9]{1,18}')->name('draft.confirm');
        Route::post('/drafts/{draft}/dismiss', [AssistantController::class, 'dismiss'])->where('draft', '[0-9]{1,18}')->name('draft.dismiss');
    });

    // What needs attention: reading needs view-finance; changing a finding's state needs record-entries.
    Route::middleware('can:view-finance')->get('/attention', [AttentionController::class, 'index'])->name('intel.attention.index');
    Route::middleware('can:record-entries')->prefix('attention')->name('intel.attention.')->group(function () {
        Route::post('/scan', [AttentionController::class, 'scan'])->middleware('throttle:6,1')->name('scan');
        Route::post('/{insight}/dismiss', [AttentionController::class, 'dismiss'])->where('insight', '[0-9]{1,18}')->name('dismiss');
        Route::post('/{insight}/snooze', [AttentionController::class, 'snooze'])->where('insight', '[0-9]{1,18}')->name('snooze');
    });

    // Notifications are a person's own; the ledger scope plus recipient check keep them so.
    Route::middleware('can:view-finance')->prefix('notifications')->name('intel.notifications.')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::post('/read-all', [NotificationController::class, 'readAll'])->name('read-all');
        Route::get('/preferences', [NotificationController::class, 'preferences'])->name('preferences');
        Route::put('/preferences', [NotificationController::class, 'update'])->name('preferences.update');
        Route::post('/{notification}/read', [NotificationController::class, 'read'])->where('notification', '[0-9]{1,18}')->name('read');
    });

    // Integrations: the status page, and the review of Dot.Emall sale lines.
    Route::middleware('can:view-finance')->prefix('integrations')->name('intel.integrations.')->group(function () {
        Route::get('/', [IntegrationsController::class, 'index'])->name('index');
        Route::get('/emall', [EmallReviewController::class, 'index'])->name('emall.index');
    });
    Route::middleware('can:post-journal')->prefix('integrations/emall')->name('intel.integrations.emall.')->group(function () {
        Route::post('/{line}/post', [EmallReviewController::class, 'post'])->where('line', '[0-9]{1,18}')->name('post');
        Route::post('/{line}/reject', [EmallReviewController::class, 'reject'])->where('line', '[0-9]{1,18}')->name('reject');
    });
});
