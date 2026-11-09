<?php

namespace App\Http\Controllers\Bank;

use App\Enums\Capability;
use App\Exceptions\Bank\ReconciliationRefused;
use App\Http\Controllers\Controller;
use App\Support\Ledger\LedgerAccess;
use Illuminate\Http\RedirectResponse;

/**
 * Base of the bank pages. Reading needs membership of the ledger (view-finance); every change needs run-reconciliation, and
 * a few (reopen, accept a difference) additionally need post-journal, which the service asks again. A person who is not in the
 * ledger has no access at all; an id from another ledger never gets this far (route binding is scoped and answers 404).
 */
abstract class BankController extends Controller
{
    protected function read(): LedgerAccess
    {
        $this->authorize('view-finance');

        return LedgerAccess::current(request()->user()) ?? abort(403);
    }

    protected function work(): LedgerAccess
    {
        $this->authorize('run-reconciliation');

        return LedgerAccess::current(request()->user()) ?? abort(403);
    }

    protected function can(Capability $capability): bool
    {
        return LedgerAccess::current(request()->user())?->can($capability) ?? false;
    }

    /** Turn a refusal into a message on the page it came from, with nothing written. */
    protected function refused(ReconciliationRefused $e): RedirectResponse
    {
        return back()->withErrors(['bank' => $e->getMessage()])->withInput();
    }
}
