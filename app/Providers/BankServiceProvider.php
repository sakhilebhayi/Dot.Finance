<?php

namespace App\Providers;

use App\Models\Account;
use App\Models\Bank\BankReconciliation;
use App\Models\Bank\BankRule;
use App\Models\Bank\BankStatementLine;
use App\Observers\FinanceAuditObserver;
use App\Services\Bank\DocumentSources;
use App\Services\Bank\ReconciliationBalances;
use App\Support\Ledger\LedgerContext;
use App\Support\Ui\DashboardPanels;
use App\Support\Ui\Navigation;
use App\Support\Ui\StateLine;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

/**
 * R-44 Bank. Wires the module once, never from a route file (production caches the routes): the audit observer for bank
 * rules, the document sources Sales and Purchases tag, the state-line item ("N statement lines need a decision"), the
 * command-centre panel, and the extra sidebar item.
 */
class BankServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Sales and Purchases tag their OpenDocumentSource with 'bank.document-sources'; with none tagged the bank screens
        // simply do not offer "settle an invoice or bill".
        $this->app->singleton(DocumentSources::class, fn ($app) => new DocumentSources($app->tagged('bank.document-sources')));
    }

    public function boot(): void
    {
        BankRule::observe(FinanceAuditObserver::class);

        Navigation::add('bank', ['key' => 'bank-rules', 'label' => 'Bank rules', 'icon' => 'list-checks', 'routes' => ['bank.rules.index'], 'match' => ['bank.rules.*']]);

        // What needs the owner: statement lines nobody has explained yet. One cheap count; nothing when there are none.
        StateLine::extend(function (LedgerContext $ledger) {
            $count = BankStatementLine::query()->forContext($ledger)->whereNull('bank_match_id')->whereNull('reconciliation_id')->count();

            if ($count === 0) {
                return null;
            }

            return [
                'key' => 'bank-lines',
                'tone' => 'neutral',
                'count' => $count,
                'text' => $count === 1 ? '1 bank statement line needs a decision' : "{$count} bank statement lines need a decision",
                'href' => fx_route('bank.reconciliation.index'),
            ];
        }, 'bank');

        DashboardPanels::add('bank.dashboard-panel', order: 40);

        View::composer('bank.dashboard-panel', function ($view) {
            $ledger = LedgerContext::current();
            $rows = [];

            if ($ledger !== null) {
                $open = BankStatementLine::query()->forContext($ledger)->whereNull('bank_match_id')->whereNull('reconciliation_id')
                    ->toBase()->groupBy('account_id')->selectRaw('account_id, COUNT(*) AS n')->pluck('n', 'account_id');

                $last = BankReconciliation::query()->forContext($ledger)->where('status', 'completed')
                    ->toBase()->groupBy('account_id')->selectRaw('account_id, MAX(statement_date) AS reconciled_to')->pluck('reconciled_to', 'account_id');

                $ids = $open->keys()->merge($last->keys())->unique()->all();

                if ($ids !== []) {
                    $accounts = Account::query()->forContext($ledger)->whereIn('id', $ids)->orderBy('name')->get(['id', 'name']);
                    foreach ($accounts as $account) {
                        $rows[] = ['id' => $account->id, 'name' => $account->name, 'open' => (int) ($open[$account->id] ?? 0), 'reconciled_to' => isset($last[$account->id]) ? substr((string) $last[$account->id], 0, 10) : null];
                    }
                }
            }

            $view->with('rows', $rows);
        });

        $this->app->singleton(ReconciliationBalances::class);
    }
}
