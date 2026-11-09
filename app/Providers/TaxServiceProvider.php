<?php

namespace App\Providers;

use App\Models\Tax\TaxDeadline;
use App\Services\Tax\FilingAdapter;
use App\Services\Tax\Jurisdiction\JurisdictionRegistry;
use App\Services\Tax\ManualFilingAdapter;
use App\Support\Ledger\LedgerContext;
use App\Support\Ui\DashboardPanels;
use App\Support\Ui\Navigation;
use App\Support\Ui\StateLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\ServiceProvider;

/**
 * R-44 Tax. Everything registered here, never from a route file (production runs route:cache): the jurisdiction registry,
 * the one filing adapter (manual), the navigation items, the state-line items and the dashboard panel.
 */
class TaxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/tax.php', 'tax');

        $this->app->singleton(JurisdictionRegistry::class);
        // The only adapter. A real SARS adapter needs SARS's ISV programme and is deliberately absent.
        $this->app->bind(FilingAdapter::class, ManualFilingAdapter::class);
    }

    public function boot(): void
    {
        foreach ([
            ['key' => 'tax-emp201', 'label' => 'Employer taxes', 'icon' => 'users', 'routes' => ['tax.emp201.index'], 'match' => ['tax.emp201.*', 'tax.payroll.*']],
            ['key' => 'tax-income', 'label' => 'Income tax', 'icon' => 'scale', 'routes' => ['tax.income.index'], 'match' => ['tax.income.*']],
            ['key' => 'tax-provisional', 'label' => 'Provisional tax', 'icon' => 'calendar-days', 'routes' => ['tax.provisional.index']],
            ['key' => 'tax-documents', 'label' => 'Tax documents', 'icon' => 'file-text', 'routes' => ['tax.documents.index']],
            ['key' => 'tax-explain', 'label' => 'Explain and accountant pack', 'icon' => 'book-open', 'routes' => ['tax.explain.index'], 'match' => ['tax.explain.*', 'tax.pack.*']],
            ['key' => 'tax-profile', 'label' => 'Tax profile', 'icon' => 'badge-percent', 'routes' => ['tax.profile.edit'], 'match' => ['tax.profile.*', 'tax.obligations']],
        ] as $item) {
            Navigation::add('tax', $item);
        }

        StateLine::extend(function (LedgerContext $ledger) {
            $today = CarbonImmutable::today();
            $open = TaxDeadline::query()->forContext($ledger)->whereIn('status', ['open', 'prepared'])
                ->where('due_date', '<=', $today->addDays(TaxDeadline::DUE_SOON_DAYS)->toDateString());
            $overdue = (clone $open)->where('due_date', '<', $today->toDateString())->count();
            $soon = (clone $open)->where('due_date', '>=', $today->toDateString())->count();
            $items = [];

            if ($overdue > 0) {
                $items[] = ['key' => 'tax_overdue', 'tone' => 'variance', 'count' => $overdue, 'text' => $overdue === 1 ? '1 tax deadline is overdue' : "{$overdue} tax deadlines are overdue", 'href' => route('tax.calendar.index')];
            }
            if ($soon > 0) {
                $items[] = ['key' => 'tax_soon', 'tone' => 'neutral', 'count' => $soon, 'text' => $soon === 1 ? '1 tax deadline in the next two weeks' : "{$soon} tax deadlines in the next two weeks", 'href' => route('tax.calendar.index')];
            }

            return $items;
        });

        DashboardPanels::add('tax.dashboard', order: 30);

        view()->composer('tax.*', fn ($view) => $view->with('activeLedger', LedgerContext::current()));
    }
}
