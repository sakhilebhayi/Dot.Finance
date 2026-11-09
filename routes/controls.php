<?php

use App\Http\Controllers\Controls\AccessReviewController;
use App\Http\Controllers\Controls\ApprovalsController;
use App\Http\Controllers\Controls\ControlsIndexController;
use App\Http\Controllers\Controls\MembersController;
use App\Http\Controllers\Controls\PoliciesController;
use App\Http\Controllers\Controls\SpendLimitsController;
use App\Http\Middleware\ResolveLedgerContext;
use Illuminate\Support\Facades\Route;

// Controls: roles, approvals, spend limits, access review. Registers nothing but routes (route:cache).
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified', ResolveLedgerContext::class])
    ->prefix('controls')->name('controls.')->group(function () {
        Route::get('/', ControlsIndexController::class)->name('index');

        Route::get('/members', [MembersController::class, 'index'])->name('members.index');
        Route::put('/members/{member}', [MembersController::class, 'update'])->where('member', '[0-9]{1,18}')->name('members.update');

        Route::get('/policies', [PoliciesController::class, 'index'])->name('policies.index');
        Route::post('/policies', [PoliciesController::class, 'store'])->name('policies.store');
        Route::post('/policies/preset', [PoliciesController::class, 'preset'])->name('policies.preset');
        Route::put('/policies/{policy}', [PoliciesController::class, 'update'])->name('policies.update');
        Route::delete('/policies/{policy}', [PoliciesController::class, 'destroy'])->name('policies.destroy');

        Route::get('/approvals', [ApprovalsController::class, 'index'])->name('approvals.index');
        Route::post('/approvals/{approval}/approve', [ApprovalsController::class, 'approve'])->name('approvals.approve');
        Route::post('/approvals/{approval}/reject', [ApprovalsController::class, 'reject'])->name('approvals.reject');
        Route::post('/approvals/{approval}/cancel', [ApprovalsController::class, 'cancel'])->name('approvals.cancel');

        Route::get('/limits', [SpendLimitsController::class, 'index'])->name('limits.index');
        Route::post('/limits', [SpendLimitsController::class, 'store'])->name('limits.store');
        Route::delete('/limits/{limit}', [SpendLimitsController::class, 'destroy'])->name('limits.destroy');

        Route::get('/access-review', [AccessReviewController::class, 'index'])->name('access-review.index');
        Route::post('/access-review', [AccessReviewController::class, 'store'])->name('access-review.store');
        Route::get('/access-review/export', [AccessReviewController::class, 'export'])->name('access-review.export');
    });
