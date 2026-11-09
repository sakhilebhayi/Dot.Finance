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
use App\Services\Accounting\GlSync;
use App\Services\Accounting\ImpliedFxRateProvider;
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

        // The accounting layer. The rate provider is an interface so the shared-data module can bind the
        // fx_rates-backed one; the default knows only same-currency (and the rate implied by a transfer).
        $this->app->singleton(FxRateProvider::class, ImpliedFxRateProvider::class);
        $this->app->scoped(Chart::class);
        $this->app->scoped(GlSync::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Every id in a URL is a whole number that fits a bigint. Without this, /accounts/abc/edit reaches the database as
        // `id = 'abc'` and PostgreSQL answers 500 (invalid input syntax for type bigint), as does an id of 25 digits; SQLite
        // quietly says "not found". Now both are a plain 404 before any query (QA security D-1418). Tokens (uuid, share) are not here.
        foreach ([
            'account', 'application', 'approval', 'attachment', 'bankMatch', 'bankRule', 'bill', 'budget', 'category', 'claim', 'claimLine', 'detail', 'dimension', 'document', 'draft', 'entry',
            'glAccount', 'insight', 'limit', 'line', 'link', 'member', 'message', 'notification', 'party', 'payment', 'period', 'planBudget', 'policy', 'provisionalPayment', 'reconciliation',
            'recurring', 'reportExport', 'reportPreset', 'returnAdjustment', 'rule', 'run', 'scenario', 'statement', 'taxAdjustment', 'taxCorrespondence', 'taxDeadline', 'taxDocument',
            'taxPayrollRun', 'taxReturn', 'template', 'transaction',
        ] as $parameter) {
            Route::pattern($parameter, '[0-9]{1,18}');
        }

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
