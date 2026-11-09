<?php

namespace App\Http\Controllers\Reporting;

use App\Enums\Capability;
use App\Http\Controllers\Controller;
use App\Services\Reporting\ReportPeriod;
use App\Support\Ledger\LedgerAccess;
use Illuminate\Http\Request;

/** Base of the reporting pages: who may do what is asked of LedgerAccess, never decided here. */
abstract class ReportingController extends Controller
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

    /**
     * The period, comparison, basis and the few report options as they arrive in a query string or a form. Anything not in
     * the list is dropped, so a saved preset or an export can never carry a parameter nobody validated.
     *
     * @return array<string, mixed>
     */
    protected function reportInput(Request $request): array
    {
        $input = $request->validate([
            'preset' => ['nullable', 'string', 'in:'.implode(',', array_keys(ReportPeriod::PRESETS))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'compare' => ['nullable', 'string', 'in:'.implode(',', array_keys(ReportPeriod::COMPARISONS))],
            'basis' => ['nullable', 'string', 'in:accrual,cash'],
            'view' => ['nullable', 'string', 'max:20', 'alpha_dash'],
            'kind' => ['nullable', 'string', 'max:20', 'alpha_dash'],
            'top' => ['nullable', 'integer', 'between:3,50'],
            'monthly' => ['nullable', 'in:0,1'],
            'account' => ['nullable', 'integer', 'min:1'],
            'budget' => ['nullable', 'integer', 'min:1'],
        ]);

        return $input;
    }
}
