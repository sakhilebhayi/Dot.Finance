<?php

namespace App\Http\Controllers\Controls;

use App\Enums\Capability;
use App\Exceptions\Controls\ControlsViolation;
use App\Http\Controllers\Controller;
use App\Support\Ledger\LedgerAccess;
use Illuminate\Http\RedirectResponse;

/** Shared by the Controls screens: one way to turn a governance rule saying no into a response. */
abstract class ControlsBaseController extends Controller
{
    /** Allow the page when the person holds at least one of $capabilities; otherwise 403. */
    protected function authorizeAny(Capability ...$capabilities): LedgerAccess
    {
        $access = LedgerAccess::current(request()->user()) ?? abort(404);

        abort_unless(collect($capabilities)->contains(fn (Capability $c) => $access->can($c)), 403);

        return $access;
    }

    /**
     * Run a state change. A rule saying no becomes: 404 (not your ledger), 403 (not allowed to
     * do this kind of thing at all), or a message back on the form (the rest: separation of
     * duties, limits, stale amounts, invalid input), so the person can act on it.
     */
    protected function attempt(callable $change, string $done): RedirectResponse
    {
        try {
            $change();
        } catch (ControlsViolation $violation) {
            abort_if($violation->reason === 'no_access', 404);
            abort_if(in_array($violation->reason, ['missing_capability', 'personal_ledger'], true), 403);

            return back()->withErrors(['controls' => $violation->getMessage()])->withInput();
        }

        return back()->with('status', $done);
    }
}
