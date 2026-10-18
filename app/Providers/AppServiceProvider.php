<?php

namespace App\Providers;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\RecurringRule;
use App\Models\Transaction;
use App\Models\User;
use App\Observers\FinanceAuditObserver;
use App\Support\Ledger\LedgerAccess;
use App\Support\Ledger\LedgerContext;
use App\Support\Ledger\LedgerContextManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One manager per application: it holds the explicitly named contexts (console, queue)
        // for the duration of their callbacks.
        $this->app->singleton(LedgerContextManager::class);
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

        // The shell and the pages that word themselves by ledger get the active one as $activeLedger
        // (a LedgerContext, or null when none can be established).
        View::composer(['layouts.app', 'dashboard', 'accounts.*'], fn ($view) => $view->with('activeLedger', LedgerContext::current()));

        // Abilities that act on the active ledger rather than on one record. The model
        // policies cover everything that has a record to point at.
        Gate::define('record-entries', fn (User $user) => LedgerAccess::current($user)?->canRecordEntries() ?? false);
        Gate::define('view-audit', fn (User $user) => LedgerAccess::current($user)?->canViewAudit() ?? false);
    }
}
