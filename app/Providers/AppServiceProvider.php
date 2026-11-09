<?php

namespace App\Providers;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\RecurringRule;
use App\Models\Transaction;
use App\Models\User;
use App\Observers\FinanceAuditObserver;
use App\Services\AccountBalances;
use App\Services\Accounting\Chart;
use App\Services\Accounting\FxRateProvider;
use App\Services\Accounting\FxServiceRateProvider;
use App\Services\Accounting\GlSync;
use App\Support\Ledger\LedgerAccess;
use App\Support\Ledger\LedgerContext;
use App\Support\Ledger\LedgerContextManager;
use App\Support\Ui\Navigation;
use App\Support\Ui\StateLine;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
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

        // Derived balances are computed once per request (flushed on every write).
        $this->app->scoped(AccountBalances::class);

        // The accounting layer. The rate provider is an interface; the ONE binding is the fx_rates-backed service (R-44
        // integration, D-1203): no other provider may bind FxRateProvider (a test reads the source to prove it). It posts a
        // foreign-currency row at a rate on record within a week and defers it when there is none; same-currency rows need none.
        $this->app->singleton(FxRateProvider::class, FxServiceRateProvider::class);
        $this->app->scoped(Chart::class);
        $this->app->scoped(GlSync::class);
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
        View::composer(['layouts.app', 'dashboard', 'accounts.*', 'insights.*', 'audit.*'], fn ($view) => $view->with('activeLedger', LedgerContext::current()));

        // The shell: the map of the product, the quick actions and the state line ("what needs you"), computed once
        // per page render for the active ledger. Only the authenticated shell asks for them.
        View::composer('layouts.app', function ($view): void {
            $ledger = LedgerContext::current();
            $view->with([
                'fxNav' => Navigation::resolved(Route::currentRouteName()),
                'fxQuick' => Navigation::quickActions(),
                'fxState' => auth()->check() ? StateLine::for($ledger) : [],
            ]);
        });

        Paginator::defaultView('vendor.pagination.fx');
        Paginator::defaultSimpleView('vendor.pagination.fx-simple');

        // Abilities that act on the active ledger rather than on one record. The model
        // policies cover everything that has a record to point at.
        Gate::define('record-entries', fn (User $user) => LedgerAccess::current($user)?->canRecordEntries() ?? false);
        Gate::define('view-audit', fn (User $user) => LedgerAccess::current($user)?->canViewAudit() ?? false);
    }
}
