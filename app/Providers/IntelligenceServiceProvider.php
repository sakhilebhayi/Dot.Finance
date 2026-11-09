<?php

namespace App\Providers;

use App\Contracts\Integrations\PayrollSummarySink;
use App\Contracts\Intelligence\AffordabilitySource;
use App\Contracts\Intelligence\InvoiceReminderSender;
use App\Contracts\Intelligence\ModelClient;
use App\Contracts\Intelligence\PayablesSource;
use App\Contracts\Intelligence\ReceivablesSource;
use App\Contracts\Intelligence\TaxDeadlineSource;
use App\Contracts\Intelligence\TaxPositionSource;
use App\Models\Intelligence\FinanceInsight;
use App\Models\Intelligence\FinanceNotification;
use App\Services\Integrations\Hr\NullPayrollSummarySink;
use App\Services\Intelligence\AnthropicClient;
use App\Services\Intelligence\Detectors;
use App\Services\Intelligence\HandlerRegistry;
use App\Services\Intelligence\Handlers;
use App\Services\Intelligence\InsightEngine;
use App\Services\Intelligence\Sources;
use App\Support\Ledger\LedgerContext;
use App\Support\Ui\Navigation;
use App\Support\Ui\StateLine;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

/**
 * R-44 Intelligence and Integrations wiring. Everything that must survive `route:cache` lives here, never in a
 * route file: the contracts and their default implementations, the assistant's rate limiter, the navigation
 * entries, the state-line item and the bell's view composer.
 *
 * Another module replaces a default by rebinding the contract in its own provider, for example:
 *   $this->app->bind(\App\Contracts\Intelligence\ReceivablesSource::class, \App\Services\Sales\SalesReceivables::class);
 * (a provider listed AFTER this one in bootstrap/providers.php wins).
 */
class IntelligenceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/intelligence.php', 'intelligence');
        $this->mergeConfigFrom(__DIR__.'/../../config/integrations.php', 'integrations');

        // Where the numbers come from. Defaults read the ledger's own data; richer modules rebind these.
        $this->app->bind(ReceivablesSource::class, Sources\GlReceivables::class);
        $this->app->bind(PayablesSource::class, Sources\RecurringPayables::class);
        $this->app->bind(TaxPositionSource::class, Sources\GlTaxPosition::class);
        $this->app->bind(AffordabilitySource::class, Sources\ForecastAffordability::class);
        $this->app->bind(InvoiceReminderSender::class, Sources\NullInvoiceReminderSender::class);
        $this->app->bind(TaxDeadlineSource::class, Sources\NullTaxDeadlineSource::class);
        $this->app->bind(PayrollSummarySink::class, NullPayrollSummarySink::class);

        // The optional language model: off by default, and only ever behind NumberGuard.
        $this->app->singleton(ModelClient::class, AnthropicClient::class);

        $this->app->singleton(HandlerRegistry::class, fn ($app) => new HandlerRegistry([
            $app->make(Handlers\SpendHandler::class),
            $app->make(Handlers\ReceivablesHandler::class),
            $app->make(Handlers\BillsHandler::class),
            $app->make(Handlers\CashHandler::class),
            $app->make(Handlers\ProfitHandler::class),
            $app->make(Handlers\TaxHandler::class),
            $app->make(Handlers\AttentionHandler::class),
            $app->make(Handlers\BudgetHandler::class),
            $app->make(Handlers\AffordHandler::class),
            $app->make(Handlers\DraftHandlers::class),
        ]));

        $this->app->singleton(InsightEngine::class, fn ($app) => new InsightEngine([
            $app->make(Detectors\ExpenseSpikeDetector::class),
            $app->make(Detectors\DuplicateDetector::class),
            $app->make(Detectors\RoundAmountDetector::class),
            $app->make(Detectors\BankDetailChangeDetector::class),
            $app->make(Detectors\DormantCustomerDetector::class),
            $app->make(Detectors\ConcentrationDetector::class),
            $app->make(Detectors\MarginDropDetector::class),
            $app->make(Detectors\RunwayDetector::class),
            $app->make(Detectors\BooksVsCashDetector::class),
            $app->make(Detectors\SuspenseBalanceDetector::class),
            $app->make(Detectors\DuplicatePartyDetector::class),
        ]));
    }

    public function boot(): void
    {
        // Questions per person per ledger per minute: an assistant that can be asked in a loop is a load problem.
        RateLimiter::for('finance-assistant', fn (Request $request) => Limit::perMinute((int) config('intelligence.assistant.per_minute', 12))
            ->by(($request->user()?->id ?? $request->ip()).'|'.(LedgerContext::current()?->key() ?? 'none')));

        Navigation::add('money', ['key' => 'assistant', 'label' => 'Assistant', 'icon' => 'send', 'routes' => ['intel.assistant.index'], 'match' => ['intel.assistant.*'], 'can' => 'view-finance']);
        Navigation::add('money', ['key' => 'attention', 'label' => 'What needs attention', 'icon' => 'triangle-alert', 'routes' => ['intel.attention.index'], 'match' => ['intel.attention.*'], 'can' => 'view-finance']);
        Navigation::add('money', ['key' => 'notifications', 'label' => 'Notifications', 'icon' => 'bell', 'routes' => ['intel.notifications.index'], 'match' => ['intel.notifications.*'], 'can' => 'view-finance']);
        Navigation::add('controls', ['key' => 'integrations', 'label' => 'Integrations', 'icon' => 'external-link', 'routes' => ['intel.integrations.index'], 'match' => ['intel.integrations.*'], 'can' => 'view-finance']);

        // The state line: one item for the open findings, red only when something is a warning or worse.
        StateLine::extend(function (LedgerContext $ledger): ?array {
            $rows = FinanceInsight::query()->forContext($ledger)->visible()->selectRaw('severity, COUNT(*) AS n')->groupBy('severity')->pluck('n', 'severity');
            $count = (int) $rows->sum();

            if ($count === 0) {
                return null;
            }

            $serious = (int) ($rows['warning'] ?? 0) + (int) ($rows['critical'] ?? 0);

            return [
                'key' => 'attention-findings',
                'tone' => $serious > 0 ? 'variance' : 'neutral',
                'count' => $count,
                'text' => $count === 1 ? '1 thing in the books is worth a look' : "{$count} things in the books are worth a look",
                'href' => fx_route('intel.attention.index'),
            ];
        }, 'intel.attention');

        // The pages word themselves by ledger, like the audit and controls pages do.
        View::composer(['intel.*', 'integrations.*'], fn ($view) => $view->with('activeLedger', LedgerContext::current()));

        // The bell: this person's unread count in the active ledger, for the shell.
        View::composer('layouts.app', function ($view): void {
            $ledger = LedgerContext::current();
            $user = auth()->user();
            $unread = $ledger && $user
                ? FinanceNotification::query()->forContext($ledger)->for($user)->unread()->count()
                : 0;

            $view->with('intelUnread', $unread);
        });
    }
}
