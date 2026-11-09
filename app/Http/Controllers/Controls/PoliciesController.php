<?php

namespace App\Http\Controllers\Controls;

use App\Enums\ApprovalSubject;
use App\Enums\Capability;
use App\Enums\FinanceRole;
use App\Models\Account;
use App\Models\Controls\ApprovalPolicy;
use App\Rules\MoneyAmount;
use App\Services\Controls\ApprovalPolicies;
use App\Services\Controls\ApprovalPresets;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Approval policies: off until a person turns one on (a preset in one click, or a custom rule). */
class PoliciesController extends ControlsBaseController
{
    public function index(): View
    {
        $this->authorizeAny(Capability::ManageApprovals, Capability::ViewAudit);

        return view('controls.policies', [
            'policies' => ApprovalPolicy::query()->orderBy('subject_type')->orderBy('id')->get(),
            'presets' => ApprovalPresets::all(),
            'subjects' => ApprovalSubject::cases(),
            'roles' => FinanceRole::cases(),
            'currency' => Account::query()->orderBy('id')->value('currency') ?? 'ZAR',
            'canManage' => $this->authorizeAny(Capability::ViewFinance)->can(Capability::ManageApprovals),
        ]);
    }

    public function store(Request $request, ApprovalPolicies $policies): RedirectResponse
    {
        $this->authorizeAny(Capability::ManageApprovals);

        $data = $request->validate($this->rules());
        $context = $this->ledger();

        return $this->attempt(fn () => $policies->create($request->user(), $context, $this->fields($data, $request)), 'Policy added.');
    }

    public function preset(Request $request, ApprovalPolicies $policies): RedirectResponse
    {
        $this->authorizeAny(Capability::ManageApprovals);

        $data = $request->validate([
            'preset' => ['required', 'string', Rule::in(array_keys(ApprovalPresets::all()))],
            'amount' => ['nullable', new MoneyAmount(allowZero: true)],
            'currency' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
        ]);

        $context = $this->ledger();
        $cents = filled($data['amount'] ?? null) ? Money::parse((string) $data['amount']) : null;

        return $this->attempt(fn () => $policies->applyPreset($request->user(), $context, $data['preset'], $cents, filled($data['currency'] ?? null) ? strtoupper($data['currency']) : null), 'Policy turned on.');
    }

    public function update(Request $request, ApprovalPolicy $policy, ApprovalPolicies $policies): RedirectResponse
    {
        $this->authorizeAny(Capability::ManageApprovals);

        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $context = $this->ledger();

        return $this->attempt(fn () => $policies->update($request->user(), $context, $policy, ['is_active' => (bool) $data['is_active']]), $data['is_active'] ? 'Policy turned on.' : 'Policy turned off.');
    }

    public function destroy(Request $request, ApprovalPolicy $policy, ApprovalPolicies $policies): RedirectResponse
    {
        $this->authorizeAny(Capability::ManageApprovals);
        $context = $this->ledger();

        return $this->attempt(fn () => $policies->delete($request->user(), $context, $policy), 'Policy removed.');
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'subject_type' => ['required', 'string', Rule::in(ApprovalSubject::values())],
            'name' => ['nullable', 'string', 'max:160'],
            'amount' => ['nullable', new MoneyAmount(allowZero: true)],
            'currency' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'min_role' => ['nullable', 'string', Rule::in(FinanceRole::values())],
            'steps_required' => ['nullable', 'integer', 'between:1,3'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(array $data, Request $request): array
    {
        $fields = array_filter([
            'subject_type' => $data['subject_type'] ?? null,
            'name' => $data['name'] ?? null,
            'currency' => $data['currency'] ?? null,
            'min_role' => $data['min_role'] ?? null,
            'steps_required' => isset($data['steps_required']) ? (int) $data['steps_required'] : null,
        ], fn ($v) => $v !== null);

        $fields['threshold_cents'] = filled($data['amount'] ?? null) ? Money::parse((string) $data['amount']) : null;
        $fields['allow_self_approval'] = $request->boolean('allow_self_approval');
        $fields['is_active'] = true;

        return $fields;
    }
}
