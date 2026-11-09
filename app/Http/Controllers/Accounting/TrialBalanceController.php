<?php

namespace App\Http\Controllers\Accounting;

use App\Services\Accounting\TrialBalance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TrialBalanceController extends AccountingController
{
    public function __invoke(Request $request, TrialBalance $trialBalance): View|RedirectResponse
    {
        $this->access();
        if ($redirect = $this->unlessEnabled()) {
            return $redirect;
        }

        $validated = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d']]);
        $asOf = $validated['as_of'] ?? now()->toDateString();

        return view('accounting.trial-balance', ['report' => $trialBalance->asOf($this->ledger(), $asOf), 'asOf' => $asOf, 'settings' => $this->settings()]);
    }
}
