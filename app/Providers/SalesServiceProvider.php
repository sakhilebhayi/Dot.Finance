<?php

namespace App\Providers;

use App\Console\Commands\Sales\CheckReceivables;
use App\Console\Commands\Sales\ExpireQuotes;
use App\Console\Commands\Sales\RunRecurring;
use App\Console\Commands\Sales\SendReminders;
use App\Models\Sales\SalesDocument;
use App\Policies\Sales\SalesDocumentPolicy;
use App\Services\Sales\ApplicationEngine;
use App\Services\Sales\CashSettlement;
use App\Services\Sales\CreditNotes;
use App\Services\Sales\DocumentComputer;
use App\Services\Sales\DocumentDrafts;
use App\Services\Sales\DocumentIssuer;
use App\Services\Sales\DocumentLifecycle;
use App\Services\Sales\DocumentMailer;
use App\Services\Sales\DocumentRenderer;
use App\Services\Sales\Gateways\ManualGateway;
use App\Services\Sales\ReceiptService;
use App\Services\Sales\ReceivablesLedger;
use App\Services\Sales\RecurringInvoices;
use App\Services\Sales\Reminders;
use App\Services\Sales\SalesPosting;
use App\Services\Sales\SalesProfiles;
use App\Services\Sales\ShareLinks;
use App\Services\Sales\Statements;
use App\Services\Sales\Support\ProfileVatStatus;
use App\Services\Sales\Support\VatStatus;
use App\Services\Sales\TaxInvoiceChecklist;
use App\Services\Sales\WriteOffs;
use App\Support\Ledger\LedgerContext;
use App\Support\Ui\DashboardPanels;
use App\Support\Ui\Navigation;
use App\Support\Ui\StateLine;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * R-44 Sales. Binds the services, the document policy, the throttle for customer links, the sidebar entries, the
 * Command centre panel and the "needs you" line. Nothing is registered from a route file (production caches routes).
 */
class SalesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/sales.php', 'sales');

        // The VAT status comes from the Sales business profile until the Tax module binds its financial profile here.
        $this->app->bind(VatStatus::class, ProfileVatStatus::class);

        foreach ([
            SalesProfiles::class, DocumentComputer::class, DocumentDrafts::class, DocumentIssuer::class, DocumentLifecycle::class, SalesPosting::class,
            TaxInvoiceChecklist::class, CreditNotes::class, ApplicationEngine::class, ReceiptService::class, CashSettlement::class, ReceivablesLedger::class,
            Statements::class, WriteOffs::class, Reminders::class, RecurringInvoices::class, ShareLinks::class, DocumentRenderer::class, DocumentMailer::class,
            ManualGateway::class,
        ] as $service) {
            $this->app->scoped($service);
        }
    }

    public function boot(): void
    {
        Gate::policy(SalesDocument::class, SalesDocumentPolicy::class);

        // Customer links are public, so they are throttled per link and address.
        RateLimiter::for('sales-share', fn (Request $request) => Limit::perMinute((int) config('sales.share.throttle_per_minute'))
            ->by(($request->route('shareToken') ?? 'none').'|'.$request->ip()));

        $this->navigation();

        DashboardPanels::add('sales.dashboard-panel', order: 20);
        StateLine::extend(fn (?LedgerContext $ledger) => $this->stateItems($ledger));

        if ($this->app->runningInConsole()) {
            $this->commands([
                SendReminders::class,
                RunRecurring::class,
                ExpireQuotes::class,
                CheckReceivables::class,
            ]);
        }
    }

    private function navigation(): void
    {
        Navigation::add('sales', ['key' => 'receivables', 'label' => 'Receivables', 'icon' => 'scale', 'routes' => ['sales.receivables.index'], 'can' => 'view-finance']);
        Navigation::add('sales', ['key' => 'credit-notes', 'label' => 'Credit notes', 'icon' => 'receipt', 'routes' => ['sales.credit-notes.index'], 'can' => 'view-finance']);
        Navigation::add('sales', ['key' => 'payments-in', 'label' => 'Payments received', 'icon' => 'hand-coins', 'routes' => ['sales.payments.index'], 'can' => 'view-finance']);
        Navigation::add('sales', ['key' => 'recurring-invoices', 'label' => 'Repeating invoices', 'icon' => 'repeat', 'routes' => ['sales.recurring.index'], 'can' => 'issue-invoice']);
        Navigation::add('sales', ['key' => 'sales-profile', 'label' => 'Business profile', 'icon' => 'settings', 'routes' => ['sales.profile.edit'], 'can' => 'manage-tax']);
    }

    /** @return list<array<string, mixed>> */
    private function stateItems(?LedgerContext $ledger): array
    {
        if ($ledger === null || ! Schema::hasTable('sales_documents')) {
            return [];
        }

        $items = [];
        $overdue = SalesDocument::query()->open()->whereNotNull('due_date')->where('due_date', '<', now()->toDateString())->count();
        if ($overdue > 0) {
            $items[] = ['key' => 'invoices-overdue', 'tone' => 'variance', 'count' => $overdue, 'text' => $overdue === 1 ? '1 invoice is past its due date' : "{$overdue} invoices are past their due date", 'href' => fx_route('sales.invoices.index', ['status' => 'overdue'])];
        }

        $waiting = SalesDocument::query()->where('status', 'awaiting_approval')->count();
        if ($waiting > 0) {
            $items[] = ['key' => 'invoices-waiting', 'tone' => 'neutral', 'count' => $waiting, 'text' => $waiting === 1 ? '1 invoice is waiting for approval' : "{$waiting} invoices are waiting for approval", 'href' => fx_route('sales.invoices.index', ['status' => 'awaiting_approval'])];
        }

        return $items;
    }
}
