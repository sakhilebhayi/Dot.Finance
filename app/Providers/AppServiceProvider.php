<?php

namespace App\Providers;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\RecurringRule;
use App\Models\Transaction;
use App\Observers\FinanceAuditObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Every create / update / delete of a ledger record writes an audit row.
        foreach ([Transaction::class, Account::class, Category::class, Budget::class, RecurringRule::class] as $model) {
            $model::observe(FinanceAuditObserver::class);
        }
    }
}
