<?php

namespace App\Providers;

use App\Console\Commands\PruneReportExports;
use App\Contracts\Planning\PayrollCommitmentsProvider;
use App\Contracts\Planning\TaxObligationsProvider;
use App\Contracts\Reporting\OpenItemsProvider;
use App\Contracts\Reporting\TaxSummaryProvider;
use App\Models\Planning\Dimension;
use App\Models\Planning\PlanBudget;
use App\Models\Planning\Scenario;
use App\Models\Reporting\ReportPreset;
use App\Observers\FinanceAuditObserver;
use App\Services\Planning\NullObligationProviders;
use App\Services\Planning\Reports\BudgetVsActual;
use App\Services\Reporting\GlOpenItemsProvider;
use App\Services\Reporting\NullTaxSummaryProvider;
use App\Services\Reporting\ReportRegistry;
use App\Services\Reporting\Reports\AgeingReport;
use App\Services\Reporting\Reports\BalanceSheet;
use App\Services\Reporting\Reports\BreakdownReport;
use App\Services\Reporting\Reports\CashFlowStatement;
use App\Services\Reporting\Reports\DimensionPerformance;
use App\Services\Reporting\Reports\GeneralLedgerReport;
use App\Services\Reporting\Reports\ProfitAndLoss;
use App\Services\Reporting\Reports\TaxSummaryReport;
use App\Services\Reporting\Reports\TrialBalanceReport;
use App\Services\Reporting\Reports\VatReport;
use App\Support\Ui\Navigation;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * R-44 Reporting and Planning (wave 2): the report registry, the default providers for what other modules will supply
 * (open invoices and bills, tax summary, tax obligations, payroll), audit observers, navigation and the nightly prune.
 *
 * To connect a module, bind its implementation over the default in that module's own provider, for example
 * $this->app->bind(OpenItemsProvider::class, SalesOpenItems::class). Nothing in Reporting or Planning needs to change.
 */
class ReportingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/reporting.php', 'reporting');

        $this->app->bind(OpenItemsProvider::class, GlOpenItemsProvider::class);
        $this->app->bind(TaxSummaryProvider::class, NullTaxSummaryProvider::class);
        $this->app->bind(TaxObligationsProvider::class, NullObligationProviders::class);
        $this->app->bind(PayrollCommitmentsProvider::class, NullObligationProviders::class);

        $this->app->singleton(ReportRegistry::class, function ($app) {
            $registry = new ReportRegistry($app);
            $registry->register('profit-loss', fn () => $app->make(ProfitAndLoss::class));
            $registry->register('balance-sheet', fn () => $app->make(BalanceSheet::class));
            $registry->register('cash-flow-statement', fn () => $app->make(CashFlowStatement::class));
            $registry->register('trial-balance', fn () => $app->make(TrialBalanceReport::class));
            $registry->register('general-ledger', fn () => $app->make(GeneralLedgerReport::class));
            $registry->register('ageing-receivables', fn () => $app->makeWith(AgeingReport::class, ['kind' => 'receivable']));
            $registry->register('ageing-payables', fn () => $app->makeWith(AgeingReport::class, ['kind' => 'payable']));
            $registry->register('vat', fn () => $app->make(VatReport::class));
            $registry->register('tax-summary', fn () => $app->make(TaxSummaryReport::class));
            $registry->register('revenue', fn () => $app->makeWith(BreakdownReport::class, ['class' => 'income']));
            $registry->register('expenses', fn () => $app->makeWith(BreakdownReport::class, ['class' => 'expense']));
            $registry->register('performance', fn () => $app->make(DimensionPerformance::class));
            $registry->register('budget-vs-actual', fn () => $app->make(BudgetVsActual::class));

            return $registry;
        });
    }

    public function boot(): void
    {
        foreach ([PlanBudget::class, Scenario::class, Dimension::class, ReportPreset::class] as $model) {
            $model::observe(FinanceAuditObserver::class);
        }

        // Items the shell map does not list. The names the shell already knows (reports.index, reports.profit-loss,
        // reports.balance-sheet, planning.scenarios.index) become links by themselves once registered.
        Navigation::add('reports', ['key' => 'cash-flow-statement', 'label' => 'Cash-flow statement', 'icon' => 'chart-column', 'routes' => ['reports.cash-flow-statement']]);
        Navigation::add('reports', ['key' => 'ageing', 'label' => 'Receivables ageing', 'icon' => 'clock', 'routes' => ['reports.ageing-receivables']]);
        Navigation::add('reports', ['key' => 'budget-vs-actual', 'label' => 'Budget against actual', 'icon' => 'scale', 'routes' => ['reports.budget-vs-actual']]);
        Navigation::add('planning', ['key' => 'plans', 'label' => 'Plans', 'icon' => 'piggy-bank', 'routes' => ['planning.budgets.index']]);
        Navigation::add('planning', ['key' => 'cash-projection', 'label' => 'Cash position', 'icon' => 'landmark', 'routes' => ['planning.cash.index']]);
        Navigation::add('planning', ['key' => 'forecasts', 'label' => 'Forecasts', 'icon' => 'chart-line', 'routes' => ['planning.forecasts.index']]);
        Navigation::add('planning', ['key' => 'afford', 'label' => 'Can I afford it', 'icon' => 'sliders-horizontal', 'routes' => ['planning.afford']]);

        $this->commands([PruneReportExports::class]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(PruneReportExports::class)->dailyAt('03:20')->withoutOverlapping()->onOneServer();
        });
    }
}
