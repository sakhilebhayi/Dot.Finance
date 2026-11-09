<?php

namespace App\Http\Controllers\Accounting;

use App\Models\Accounting\GlAccount;
use App\Services\Accounting\GlQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GeneralLedgerController extends AccountingController
{
    public function __invoke(Request $request, GlQuery $query): View|RedirectResponse
    {
        $this->access();
        if ($redirect = $this->unlessEnabled()) {
            return $redirect;
        }

        $context = $this->ledger();
        $validated = $request->validate([
            'account' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $accounts = GlAccount::query()->orderBy('code')->get();
        // The account is looked up through the scoped model, so another ledger's id is simply "not found".
        $account = isset($validated['account']) ? ($accounts->firstWhere('id', (int) $validated['account']) ?? abort(404)) : null;

        $ledger = $account ? $query->ledgerLines($context, $account, $validated['from'] ?? null, $validated['to'] ?? null, 50, (int) ($validated['page'] ?? 1)) : null;

        return view('accounting.general-ledger', [
            'accounts' => $accounts, 'account' => $account, 'ledger' => $ledger, 'filters' => $validated, 'query' => $query,
        ]);
    }
}
