<?php

namespace App\Http\Controllers\Controls;

use App\Enums\Capability;
use App\Enums\FinanceRole;
use App\Models\Account;
use App\Models\Controls\SpendLimit;
use App\Models\User;
use App\Rules\MoneyAmount;
use App\Services\Controls\LedgerRoles;
use App\Services\Controls\SpendLimits;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** What each approver, or each role, may approve. */
class SpendLimitsController extends ControlsBaseController
{
    public function index(LedgerRoles $roles): View
    {
        $access = $this->authorizeAny(Capability::ManageApprovals, Capability::ViewAudit);

        return view('controls.limits', [
            'limits' => SpendLimit::query()->with('member:id,name')->orderBy('id')->get(),
            'members' => $roles->members($this->ledger())->pluck('user'),
            'roles' => FinanceRole::cases(),
            'periods' => SpendLimit::PERIODS,
            'currency' => Account::query()->orderBy('id')->value('currency') ?? 'ZAR',
            'canManage' => $access->can(Capability::ManageApprovals),
        ]);
    }

    public function store(Request $request, LedgerRoles $roles, SpendLimits $limits): RedirectResponse
    {
        $this->authorizeAny(Capability::ManageApprovals);

        $data = $request->validate([
            'who' => ['required', 'string', 'regex:/^(user:\d+|role:[a-z_]+)$/'],
            'currency' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'period' => ['required', Rule::in(array_keys(SpendLimit::PERIODS))],
            'amount' => ['required', new MoneyAmount],
        ]);

        $context = $this->ledger();
        [$kind, $value] = explode(':', $data['who'], 2);
        $member = null;
        $role = null;

        if ($kind === 'user') {
            $member = $roles->members($context)->pluck('user')->firstWhere('id', (int) $value);
            abort_unless($member instanceof User, 404);
        } else {
            $role = FinanceRole::tryFrom($value) ?? abort(422);
        }

        return $this->attempt(fn () => $limits->set($request->user(), $context, $member, $role, strtoupper($data['currency']), $data['period'], Money::parse((string) $data['amount'])), 'Limit saved.');
    }

    public function destroy(Request $request, SpendLimit $limit, SpendLimits $limits): RedirectResponse
    {
        $this->authorizeAny(Capability::ManageApprovals);
        $context = $this->ledger();

        return $this->attempt(fn () => $limits->remove($request->user(), $context, $limit), 'Limit removed.');
    }
}
