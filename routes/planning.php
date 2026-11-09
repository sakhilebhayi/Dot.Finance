<?php

use App\Http\Controllers\Planning\AffordController;
use App\Http\Controllers\Planning\CashController;
use App\Http\Controllers\Planning\DimensionController;
use App\Http\Controllers\Planning\ForecastController;
use App\Http\Controllers\Planning\PlanBudgetController;
use App\Http\Controllers\Planning\ScenarioController;
use App\Http\Middleware\ResolveLedgerContext;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Planning (R-44 wave 2): plans, cash position and projection, forecasts, scenarios
|--------------------------------------------------------------------------
| Required from routes/web.php. Routes only (production runs route:cache). Nothing here writes to the books; plans,
| scenarios and dimensions are rows of their own. Model binding goes through the ledger scope, so another ledger's id is
| a 404 before any capability check.
*/
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified', ResolveLedgerContext::class])
    ->prefix('planning')->name('planning.')->group(function () {
        Route::get('/budgets', [PlanBudgetController::class, 'index'])->name('budgets.index');
        Route::get('/budgets/create', [PlanBudgetController::class, 'create'])->name('budgets.create');
        Route::post('/budgets', [PlanBudgetController::class, 'store'])->name('budgets.store');
        Route::get('/budgets/{planBudget}', [PlanBudgetController::class, 'show'])->where('planBudget', '[0-9]{1,18}')->name('budgets.show');
        Route::get('/budgets/{planBudget}/edit', [PlanBudgetController::class, 'edit'])->where('planBudget', '[0-9]{1,18}')->name('budgets.edit');
        Route::put('/budgets/{planBudget}', [PlanBudgetController::class, 'update'])->where('planBudget', '[0-9]{1,18}')->name('budgets.update');
        Route::post('/budgets/{planBudget}/submit', [PlanBudgetController::class, 'submit'])->where('planBudget', '[0-9]{1,18}')->name('budgets.submit');
        Route::post('/budgets/{planBudget}/version', [PlanBudgetController::class, 'version'])->where('planBudget', '[0-9]{1,18}')->name('budgets.version');
        Route::post('/budgets/{planBudget}/reforecast', [PlanBudgetController::class, 'reforecast'])->where('planBudget', '[0-9]{1,18}')->name('budgets.reforecast');

        Route::get('/dimensions', [DimensionController::class, 'index'])->name('dimensions.index');
        Route::post('/dimensions', [DimensionController::class, 'store'])->name('dimensions.store');
        Route::put('/dimensions/{dimension}', [DimensionController::class, 'update'])->where('dimension', '[0-9]{1,18}')->name('dimensions.update');

        Route::get('/cash', [CashController::class, 'index'])->name('cash.index');
        Route::get('/forecasts', [ForecastController::class, 'index'])->name('forecasts.index');
        Route::get('/afford', [AffordController::class, 'show'])->name('afford');

        Route::get('/scenarios', [ScenarioController::class, 'index'])->name('scenarios.index');
        Route::get('/scenarios/create', [ScenarioController::class, 'create'])->name('scenarios.create');
        Route::post('/scenarios', [ScenarioController::class, 'store'])->name('scenarios.store');
        Route::get('/scenarios/{scenario}', [ScenarioController::class, 'show'])->where('scenario', '[0-9]{1,18}')->name('scenarios.show');
        Route::get('/scenarios/{scenario}/edit', [ScenarioController::class, 'edit'])->where('scenario', '[0-9]{1,18}')->name('scenarios.edit');
        Route::put('/scenarios/{scenario}', [ScenarioController::class, 'update'])->where('scenario', '[0-9]{1,18}')->name('scenarios.update');
        Route::delete('/scenarios/{scenario}', [ScenarioController::class, 'destroy'])->where('scenario', '[0-9]{1,18}')->name('scenarios.destroy');
    });
