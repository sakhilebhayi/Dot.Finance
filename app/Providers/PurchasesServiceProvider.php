<?php

namespace App\Providers;

use App\Models\Purchases\Bill;
use App\Models\Purchases\ExpenseClaim;
use App\Models\Purchases\ExpenseClaimLine;
use App\Models\Purchases\PaymentRun;
use App\Models\Purchases\PurchaseSetting;
use App\Models\Purchases\RecurringBill;
use App\Models\Purchases\SupplierProfile;
use App\Observers\FinanceAuditObserver;
use App\Policies\Purchases\PurchasePolicy;
use App\Services\Purchases\ApLedger;
use App\Services\Purchases\Extraction\ExtractorManager;
use App\Services\Purchases\MissingDocuments;
use App\Services\Purchases\PurchaseLedger;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use App\Support\Ui\DashboardPanels;
use App\Support\Ui\Navigation;
use App\Support\Ui\StateLine;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Wires Purchases. Everything that must survive route:cache lives here and not in routes/purchases.php: the morph aliases (a
 * payment allocation to a bill stores 'bill', not a class name), the policies, the audit observers, the navigation, the state line
 * and the dashboard panels.
 */
class PurchasesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/purchases.php', 'purchases');

        $this->app->singleton(ExtractorManager::class);
    }

    public function boot(): void
    {
        Relation::morphMap([
            'bill' => Bill::class,
            'expense_claim' => ExpenseClaim::class,
            'expense_claim_line' => ExpenseClaimLine::class,
        ]);

        foreach ([Bill::class, ExpenseClaim::class, ExpenseClaimLine::class, PaymentRun::class, RecurringBill::class] as $model) {
            Gate::policy($model, PurchasePolicy::class);
        }

        foreach ([Bill::class, ExpenseClaim::class, PaymentRun::class, RecurringBill::class, PurchaseSetting::class, SupplierProfile::class] as $model) {
            $model::observe(FinanceAuditObserver::class);
        }

        $this->navigation();
        $this->stateLine();

        DashboardPanels::add('purchases.dashboard.attention', 30);
        DashboardPanels::add('purchases.dashboard.trends', 40);
    }

    private function navigation(): void
    {
        $group = 'purchases';
        Navigation::add($group, ['key' => 'purchase-payments', 'label' => 'Supplier payments', 'icon' => 'send', 'routes' => ['purchases.payments.index'], 'match' => ['purchases.payments.*', 'purchases.runs.*']]);
        Navigation::add($group, ['key' => 'purchase-receipts', 'label' => 'Receipts inbox', 'icon' => 'inbox', 'routes' => ['purchases.receipts.index'], 'can' => 'record-entries', 'match' => ['purchases.receipts.*']]);
        Navigation::add($group, ['key' => 'purchase-documents', 'label' => 'Needs documents', 'icon' => 'file-up', 'routes' => ['purchases.documents.index'], 'match' => ['purchases.documents.*']]);
        Navigation::add($group, ['key' => 'purchase-ap', 'label' => 'Payables and ageing', 'icon' => 'scale', 'routes' => ['purchases.ap.index'], 'match' => ['purchases.ap.*']]);
        Navigation::add($group, ['key' => 'purchase-recurring', 'label' => 'Recurring bills', 'icon' => 'repeat', 'routes' => ['purchases.recurring.index'], 'can' => 'enter-bill', 'match' => ['purchases.recurring.*']]);
        Navigation::add($group, ['key' => 'purchase-settings', 'label' => 'Purchases settings', 'icon' => 'settings', 'routes' => ['purchases.settings.edit'], 'match' => ['purchases.settings.*']]);
    }

    private function stateLine(): void
    {
        StateLine::extend(function (LedgerContext $ledger) {
            $items = [];
            $today = CarbonImmutable::today()->toDateString();

            $overdue = Bill::query()->where('kind', 'bill')->whereIn('status', ['open', 'partially_paid'])->where('due_date', '<', $today)->count();
            if ($overdue > 0 && ($url = fx_route(['purchases.bills.index']))) {
                $items[] = ['key' => 'bills-overdue', 'tone' => 'variance', 'count' => $overdue, 'text' => $overdue === 1 ? '1 supplier bill is overdue' : "{$overdue} supplier bills are overdue", 'href' => $url.'?status=overdue'];
            }

            $waiting = Bill::query()->where('status', 'awaiting_approval')->count() + ExpenseClaim::query()->where('status', 'awaiting_approval')->count();
            if ($waiting > 0 && ($url = fx_route(['controls.approvals.index', 'approvals.index']))) {
                $items[] = ['key' => 'purchases-awaiting', 'tone' => 'neutral', 'count' => $waiting, 'text' => $waiting === 1 ? '1 bill or claim is waiting for approval' : "{$waiting} bills and claims are waiting for approval", 'href' => $url];
            }

            // the two heavy checks are cached briefly: the state line runs on every page
            $ttl = (int) config('purchases.state_line_cache_seconds', 60);
            $remember = fn (string $key, \Closure $compute) => $ttl > 0 ? Cache::remember('purchases:'.$key.':'.$ledger->key(), $ttl, $compute) : $compute();

            $missing = $remember('missing-documents', fn () => app(MissingDocuments::class)->countForLedger($ledger));
            if ($missing > 0 && ($url = fx_route(['purchases.documents.index']))) {
                $items[] = ['key' => 'documents-missing', 'tone' => 'neutral', 'count' => $missing, 'text' => $missing === 1 ? '1 record has no document attached' : "{$missing} records have no document attached", 'href' => $url];
            }

            $unbalanced = $remember('ap-reconcile', fn () => ['ok' => app(ApLedger::class)->reconcile($ledger)] + ['accounting_on' => app(PurchaseLedger::class)->accountingEnabled($ledger)]);
            if ($unbalanced['accounting_on'] && ! $unbalanced['ok']['ok'] && ($url = fx_route(['purchases.ap.index']))) {
                $items[] = ['key' => 'ap-out', 'tone' => 'variance', 'count' => 1, 'text' => 'The supplier ledger is '.Money::format(abs((int) $unbalanced['ok']['difference_cents'])).' away from the books', 'href' => $url.'#reconciling-items'];
            }

            return $items;
        }, 'purchases');
    }
}
