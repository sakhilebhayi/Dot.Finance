<?php

namespace App\Http\Controllers\Accounting;

use App\Models\Accounting\AccountingPeriod;
use App\Services\Accounting\Exceptions\AccountingException;
use App\Services\Accounting\Periods;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PeriodController extends AccountingController
{
    public function index(): View|RedirectResponse
    {
        $this->access();
        if ($redirect = $this->unlessEnabled()) {
            return $redirect;
        }

        return view('accounting.periods.index', [
            'periods' => AccountingPeriod::query()->orderByDesc('start_date')->get(),
            'settings' => $this->settings(),
            'canClose' => $this->access()->canClosePeriod(),
        ]);
    }

    public function close(AccountingPeriod $period, Periods $periods): RedirectResponse
    {
        $this->require('canClosePeriod');

        try {
            $periods->close($this->ledger(), $period, request()->user());
        } catch (AccountingException $e) {
            return back()->withErrors(['period' => $e->getMessage()]);
        }

        return redirect()->route('accounting.periods.index')->with('status', "{$period->label()} closed. Nothing dated on or before {$period->end_date->toDateString()} can be posted, edited or deleted.");
    }

    public function closeThrough(Request $request, Periods $periods): RedirectResponse
    {
        $this->require('canClosePeriod');
        $validated = $request->validate(['through' => ['required', 'date_format:Y-m']]);

        $end = CarbonImmutable::createFromFormat('!Y-m', $validated['through'])->endOfMonth();
        if ($end->gte(CarbonImmutable::now()->startOfMonth())) {
            return back()->withErrors(['through' => 'Only a month that has ended can be closed.']);
        }

        try {
            $closed = $periods->closeThrough($this->ledger(), $end, $request->user());
        } catch (AccountingException $e) {
            return back()->withErrors(['through' => $e->getMessage()]);
        }

        return redirect()->route('accounting.periods.index')->with('status', $closed === 0 ? 'Those months were already closed.' : "{$closed} period(s) closed through {$end->toDateString()}.");
    }

    public function reopen(Request $request, AccountingPeriod $period, Periods $periods): RedirectResponse
    {
        $this->require('canClosePeriod');
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        try {
            $periods->reopen($this->ledger(), $period, $validated['reason'], $request->user());
        } catch (AccountingException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('accounting.periods.index')->with('status', "{$period->label()} reopened. The reason is in the audit log.");
    }
}
