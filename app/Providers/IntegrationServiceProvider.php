<?php

namespace App\Providers;

use App\Contracts\Integrations\PayrollSummarySink;
use App\Contracts\Intelligence\AffordabilitySource;
use App\Contracts\Intelligence\InvoiceReminderSender;
use App\Contracts\Intelligence\PayablesSource;
use App\Contracts\Intelligence\ReceivablesSource;
use App\Contracts\Intelligence\TaxDeadlineSource;
use App\Contracts\Intelligence\TaxPositionSource;
use App\Contracts\Planning\PayrollCommitmentsProvider;
use App\Contracts\Planning\TaxObligationsProvider;
use App\Contracts\Reporting\OpenItemsProvider;
use App\Contracts\Reporting\TaxSummaryProvider;
use App\Services\Planning\PlanningAffordability;
use App\Services\Purchases\PurchaseOpenDocuments;
use App\Services\Purchases\PurchasesPayables;
use App\Services\Reporting\DocumentOpenItems;
use App\Services\Sales\InvoiceReminders;
use App\Services\Sales\SalesOpenDocuments;
use App\Services\Sales\SalesReceivables;
use App\Services\Sales\Support\VatStatus;
use App\Services\Tax\TaxDeadlines;
use App\Services\Tax\TaxObligationsForPlanning;
use App\Services\Tax\TaxPayrollSummarySink;
use App\Services\Tax\TaxPosition;
use App\Services\Tax\TaxProfileVatStatus;
use App\Services\Tax\TaxSummaryForReports;
use App\Support\Ui\DashboardPanels;
use Illuminate\Support\ServiceProvider;

/**
 * R-44 integration: the one place where the contracts the modules were built against in isolation are bound to the
 * modules that implement them. It is listed LAST in bootstrap/providers.php, so every binding here wins over the honest
 * default the owning module (Intelligence, Reporting, Sales) registered. Each line below is a module saying "I have this
 * data": remove a line and the consumer goes back to saying so rather than guessing.
 *
 * Nothing is registered from a route file; a test (tests/Feature/Integration/WiringTest) fails when a contract is left on
 * its default.
 */
class IntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bank workspace: "settle an invoice or bill" (money in = Sales, money out = Purchases), through the real payment services.
        $this->app->tag([SalesOpenDocuments::class, PurchaseOpenDocuments::class], 'bank.document-sources');

        // Reporting and Planning: open items from the documents; the Tax module's summary, obligations and payroll levies.
        $this->app->bind(OpenItemsProvider::class, DocumentOpenItems::class);
        $this->app->bind(TaxSummaryProvider::class, TaxSummaryForReports::class);
        $this->app->bind(TaxObligationsProvider::class, TaxObligationsForPlanning::class);
        $this->app->bind(PayrollCommitmentsProvider::class, TaxObligationsForPlanning::class);

        // Intelligence: what the assistant, the findings and the notifications can answer once the modules have data.
        $this->app->bind(ReceivablesSource::class, SalesReceivables::class);
        $this->app->bind(PayablesSource::class, PurchasesPayables::class);
        $this->app->bind(TaxPositionSource::class, TaxPosition::class);
        $this->app->bind(TaxDeadlineSource::class, TaxDeadlines::class);
        $this->app->bind(AffordabilitySource::class, PlanningAffordability::class);
        $this->app->bind(InvoiceReminderSender::class, InvoiceReminders::class);
        $this->app->bind(PayrollSummarySink::class, TaxPayrollSummarySink::class);

        // One answer to "is this business a VAT vendor?": the Tax profile once it exists (Sales asks through this; Purchases
        // reads the same profile through PurchaseSetting::isVatRegistered).
        $this->app->bind(VatStatus::class, TaxProfileVatStatus::class);
    }

    public function boot(): void
    {
        // The Command centre: the panels of Reporting and Planning sit with the ones Sales (20), Tax (30), Purchases (30, 40) and
        // Bank (40) registered. Cash first (what you have and what is coming), then profit; each draws nothing without data.
        DashboardPanels::add('planning.dashboard-cash', order: 10);
        DashboardPanels::add('reporting.dashboard-pnl', order: 15);
    }
}
