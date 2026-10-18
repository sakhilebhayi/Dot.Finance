<?php

namespace App\Http\Controllers;

use App\Support\Ledger\LedgerContext;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * The ledger this request is about (the person's personal ledger or one company's),
     * as resolved and validated by LedgerContextManager. A request that has none is a 404:
     * there is nothing it may act on.
     */
    protected function ledger(): LedgerContext
    {
        return LedgerContext::current() ?? abort(404);
    }
}
