<?php

namespace App\Providers;

use App\Enums\Capability;
use App\Models\Controls\ApprovalPolicy;
use App\Models\Controls\SpendLimit;
use App\Models\User;
use App\Observers\FinanceAuditObserver;
use App\Services\Controls\LedgerRoles;
use App\Support\Ledger\LedgerAccess;
use App\Support\Ledger\LedgerContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Laravel\Jetstream\Events\TeamMemberRemoved;

/**
 * R-44 Controls. Registers, once and never from a route file (route:cache): one Gate per
 * capability (so `->can('approve-bill')` and `$this->authorize('manage-members')` work, all
 * answered by LedgerAccess::can), the audit observer for policies and spend limits, and the
 * reaction to a person leaving a company.
 */
class ControlsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach (Capability::cases() as $capability) {
            // record-entries and view-audit were defined by AppServiceProvider first with the same answer.
            if (! Gate::has($capability->value)) {
                Gate::define($capability->value, fn (User $user) => LedgerAccess::current($user)?->can($capability) ?? false);
            }
        }

        foreach ([ApprovalPolicy::class, SpendLimit::class] as $model) {
            $model::observe(FinanceAuditObserver::class);
        }

        // The Controls pages word themselves by ledger, like the audit page does.
        View::composer('controls.*', fn ($view) => $view->with('activeLedger', LedgerContext::current()));

        // Jetstream fires this when a member is removed or leaves. (The InfoDot sync calls the same
        // service directly when it detaches someone, because it does not go through that action.)
        Event::listen(TeamMemberRemoved::class, fn (TeamMemberRemoved $event) => app(LedgerRoles::class)->memberRemoved($event->team, $event->user));
    }
}
