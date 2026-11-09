<?php

namespace App\Http\Controllers\Controls;

use App\Enums\Capability;
use App\Enums\FinanceRole;
use App\Models\User;
use App\Services\Controls\LedgerRoles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Members and roles of the active ledger. Roles are refined here; InfoDot stays the ceiling. */
class MembersController extends ControlsBaseController
{
    public function index(LedgerRoles $roles): View
    {
        $access = $this->authorizeAny(Capability::ManageMembers, Capability::ViewAudit);

        return view('controls.members', [
            'members' => $roles->members($this->ledger()),
            'access' => $access,
            'roles' => FinanceRole::cases(),
        ]);
    }

    public function update(Request $request, int $member, LedgerRoles $roles): RedirectResponse
    {
        $this->authorizeAny(Capability::ManageMembers);

        $data = $request->validate([
            'role' => ['required', 'string', Rule::in([...FinanceRole::values(), 'inherit'])],
        ]);

        $context = $this->ledger();
        $user = $roles->members($context)->pluck('user')->firstWhere('id', $member) ?? abort(404);
        $role = $data['role'] === 'inherit' ? null : FinanceRole::from($data['role']);

        return $this->attempt(fn () => $roles->assign($request->user(), $context, $user instanceof User ? $user : abort(404), $role), 'Role updated.');
    }
}
