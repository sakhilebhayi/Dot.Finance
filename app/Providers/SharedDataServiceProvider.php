<?php

namespace App\Providers;

use App\Models\Shared\Attachment;
use App\Models\Shared\Party;
use App\Models\Shared\Payment;
use App\Models\Shared\PaymentAllocation;
use App\Observers\FinanceAuditObserver;
use App\Policies\Shared\AttachmentPolicy;
use App\Policies\Shared\PartyPolicy;
use App\Policies\Shared\PaymentPolicy;
use App\Services\Shared\Attachments;
use App\Services\Shared\FxService;
use App\Services\Shared\TaxRuleBook;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the shared data layer (parties, tax rules, numbering, attachments, payments, FX).
 * Policies and observers live here, not in a route file: production caches the routes.
 */
class SharedDataServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/shared.php', 'shared');

        $this->app->scoped(TaxRuleBook::class);
        $this->app->scoped(FxService::class);
        $this->app->singleton(Attachments::class);
    }

    public function boot(): void
    {
        Gate::policy(Party::class, PartyPolicy::class);
        Gate::policy(Attachment::class, AttachmentPolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);

        foreach ([Party::class, Payment::class, PaymentAllocation::class] as $model) {
            $model::observe(FinanceAuditObserver::class);
        }
    }
}
