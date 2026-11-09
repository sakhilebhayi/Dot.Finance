<?php

namespace App\Http\Controllers\Tax;

use App\Enums\Capability;
use App\Http\Controllers\Controller;
use App\Models\Tax\TaxProfile;
use App\Services\Tax\TaxProfiles;
use App\Support\Ledger\LedgerAccess;
use App\Support\Ledger\LedgerContext;
use Illuminate\Http\RedirectResponse;

/**
 * Base of the tax pages. Reading needs membership of the ledger (view-finance); every change asks LedgerAccess for the capability
 * that matches it: prepare-tax-return to prepare and adjust, record-tax-submission to record a submission or a payment,
 * manage-tax for the profile, view-payroll-detail for per-employee payroll.
 */
abstract class TaxController extends Controller
{
    protected function access(): LedgerAccess
    {
        return LedgerAccess::current(request()->user()) ?? abort(403);
    }

    protected function require(Capability $capability): LedgerAccess
    {
        $access = $this->access();
        abort_unless($access->can($capability), 403, 'Your role in this ledger does not allow that.');

        return $access;
    }

    protected function can(Capability $capability): bool
    {
        return $this->access()->can($capability);
    }

    protected function profile(): ?TaxProfile
    {
        return app(TaxProfiles::class)->forLedger($this->ledger());
    }

    /** The profile, or a redirect to the wizard when there is none yet. */
    protected function profileOrWizard(): TaxProfile|RedirectResponse
    {
        return $this->profile() ?? redirect()->route('tax.profile.edit')->with('status', 'Tell us about the business first: the tax pages need a tax profile.');
    }

    protected function ctx(): LedgerContext
    {
        return $this->ledger();
    }
}
