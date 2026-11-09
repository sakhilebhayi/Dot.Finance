<?php

namespace App\Http\Controllers\Planning;

use App\Enums\Capability;
use App\Http\Controllers\Controller;
use App\Models\Accounting\LedgerSetting;
use App\Services\Accounting\GlSync;
use App\Support\Ledger\LedgerAccess;
use Illuminate\Contracts\View\View;

/** Base of the planning pages. Who may do what is asked of LedgerAccess; nothing here posts to the books. */
abstract class PlanningController extends Controller
{
    protected function access(): LedgerAccess
    {
        return LedgerAccess::current(request()->user()) ?? abort(403);
    }

    protected function require(Capability $capability, ?string $message = null): LedgerAccess
    {
        $access = $this->access();
        abort_unless($access->can($capability), 403, $message ?? 'Your role in this ledger does not allow that.');

        return $access;
    }

    protected function settings(): ?LedgerSetting
    {
        return app(GlSync::class)->settings($this->ledger());
    }

    /** Planning reads the double-entry books; until they are on there is nothing to plan from. */
    protected function unlessEnabled(): ?View
    {
        if (app(GlSync::class)->isEnabled($this->ledger())) {
            return null;
        }

        return view('reports.not-enabled', ['canSetUp' => $this->access()->canManageChart()]);
    }
}
