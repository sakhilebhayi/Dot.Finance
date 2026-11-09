<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Accounting\LedgerSetting;
use App\Services\Accounting\GlSync;
use App\Support\Ledger\LedgerAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Base of the accounting pages. Every action asks LedgerAccess (the single definition of who may do what)
 * before it does anything; viewing needs only membership of the ledger, changing needs the matching capability.
 */
abstract class AccountingController extends Controller
{
    protected function access(): LedgerAccess
    {
        return LedgerAccess::current(request()->user()) ?? abort(403);
    }

    /** Abort 403 unless the signed-in person holds the capability in the active ledger. */
    protected function require(string $capability): LedgerAccess
    {
        $access = $this->access();

        abort_unless($access->{$capability}(), 403, 'Your role in this ledger does not allow that.');

        return $access;
    }

    protected function settings(): ?LedgerSetting
    {
        return app(GlSync::class)->settings($this->ledger());
    }

    /** The redirect to the setup wizard when accounting has not been enabled for this ledger; null when it is on. */
    protected function unlessEnabled(): RedirectResponse|View|null
    {
        if (app(GlSync::class)->isEnabled($this->ledger())) {
            return null;
        }

        // Someone who can switch it on is taken to the wizard; everyone else is told why there is nothing to see.
        return $this->access()->canManageChart()
            ? redirect()->route('accounting.setup')->with('status', 'Accounting is not switched on for this ledger yet.')
            : view('accounting.not-enabled');
    }
}
