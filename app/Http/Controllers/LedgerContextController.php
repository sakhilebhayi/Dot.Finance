<?php

namespace App\Http\Controllers;

use App\Support\Ledger\LedgerContextManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Switches the active ledger (personal or one of the person's companies). The choice is
 * validated against what the person belongs to; anything else is a 404 and changes nothing.
 * It lands on the dashboard because ids on the page being left belong to the old ledger.
 */
class LedgerContextController extends Controller
{
    public function update(Request $request, LedgerContextManager $contexts): RedirectResponse
    {
        $requested = $request->input('context');

        $context = $contexts->resolve($request->user(), is_string($requested) || is_int($requested) ? $requested : false);

        abort_if($context === null, 404);

        $contexts->remember($context);

        return redirect()->route('dashboard')->with('status', 'Now working in '.($context->isCompany() ? $context->label().' (company ledger).' : 'your personal ledger.'));
    }
}
