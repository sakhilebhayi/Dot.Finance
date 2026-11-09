<?php

namespace App\Services\Controls;

use App\Enums\ApprovalSubject;
use App\Enums\Capability;
use App\Enums\FinanceRole;
use App\Exceptions\Controls\ControlsViolation;
use App\Models\Controls\ApprovalPolicy;
use App\Models\User;
use App\Support\Ledger\LedgerAccess;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/** Create, change, switch off and remove approval policies, and apply presets. Needs the manage-approvals capability. */
final class ApprovalPolicies
{
    public function __construct(private readonly LedgerRoles $roles) {}

    /**
     * @param  array{subject_type: string, name?: ?string, threshold_cents?: ?int, currency?: ?string, approver_capability?: ?string, min_role?: ?string, steps_required?: int, allow_self_approval?: bool, is_active?: bool}  $data
     *
     * @throws ControlsViolation
     */
    public function create(User $actor, LedgerContext $context, array $data): ApprovalPolicy
    {
        $this->authorise($actor, $context);

        return DB::transaction(fn () => ApprovalPolicy::create($context->ownerAttributes() + $this->attributes($data) + ['created_by' => $actor->id]));
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ControlsViolation
     */
    public function update(User $actor, LedgerContext $context, ApprovalPolicy $policy, array $data): ApprovalPolicy
    {
        $this->authorise($actor, $context);
        $this->sameLedger($policy, $context);

        return DB::transaction(function () use ($policy, $data) {
            $policy->fill($this->attributes($data + [
                'subject_type' => $policy->subject_type->value,
                'name' => $policy->name,
                'threshold_cents' => $policy->threshold_cents,
                'currency' => $policy->currency,
                'approver_capability' => $policy->approver_capability->value,
                'min_role' => $policy->min_role?->value,
                'steps_required' => $policy->steps_required,
                'allow_self_approval' => $policy->allow_self_approval,
                'is_active' => $policy->is_active,
            ]))->save();

            return $policy;
        });
    }

    /** @throws ControlsViolation */
    public function delete(User $actor, LedgerContext $context, ApprovalPolicy $policy): void
    {
        $this->authorise($actor, $context);
        $this->sameLedger($policy, $context);
        $policy->delete(); // requests already asked for keep the rules they were asked under
    }

    /**
     * Turn a preset on (or update it if it is already on). In a ledger with one member the policy
     * allows self-approval, otherwise that person could never get anything approved.
     *
     * @throws ControlsViolation
     */
    public function applyPreset(User $actor, LedgerContext $context, string $key, ?int $thresholdCents = null, ?string $currency = null): ApprovalPolicy
    {
        $this->authorise($actor, $context);
        $preset = ApprovalPresets::all()[$key] ?? throw new ControlsViolation('invalid', 'Unknown preset.');

        $threshold = $preset['takes_amount'] ? ($thresholdCents ?? $preset['default_cents']) : null;
        $currency = $preset['takes_amount'] ? ($currency ?? 'ZAR') : null;

        return DB::transaction(function () use ($actor, $context, $key, $preset, $threshold, $currency) {
            $existing = ApprovalPolicy::query()->forContext($context)->where('preset_key', $key)->first();
            $data = [
                'subject_type' => $preset['subject']->value,
                'name' => ApprovalPresets::name($key, $threshold, $currency),
                'threshold_cents' => $threshold,
                'currency' => $currency,
                'approver_capability' => $preset['subject']->defaultCapability()->value,
                'min_role' => $preset['min_role']?->value,
                'steps_required' => $preset['steps'],
                'allow_self_approval' => $this->roles->memberCount($context) === 1,
                'is_active' => true,
            ];

            if ($existing !== null) {
                $existing->fill($this->attributes($data))->save();

                return $existing;
            }

            return ApprovalPolicy::create($context->ownerAttributes() + $this->attributes($data) + ['preset_key' => $key, 'created_by' => $actor->id]);
        });
    }

    /**
     * Validate and normalise policy fields.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ControlsViolation
     */
    private function attributes(array $data): array
    {
        $subject = ApprovalSubject::tryFrom((string) ($data['subject_type'] ?? '')) ?? throw new ControlsViolation('invalid', 'Choose what the policy covers.');
        $capability = Capability::tryFrom((string) ($data['approver_capability'] ?? '')) ?? $subject->defaultCapability();

        if (! in_array($capability, ApprovalSubject::approverCapabilities(), true)) {
            throw new ControlsViolation('invalid', 'That permission cannot be used to approve.');
        }

        $minRole = filled($data['min_role'] ?? null) ? (FinanceRole::tryFrom((string) $data['min_role']) ?? throw new ControlsViolation('invalid', 'Unknown role.')) : null;
        $steps = (int) ($data['steps_required'] ?? 1);

        if ($steps < 1 || $steps > 3) {
            throw new ControlsViolation('invalid', 'An approval has 1 to 3 steps.');
        }

        $threshold = $data['threshold_cents'] ?? null;
        $currency = filled($data['currency'] ?? null) ? strtoupper((string) $data['currency']) : null;

        if ($threshold !== null) {
            if (! is_int($threshold) || $threshold < 0 || $threshold > Money::MAX_CENTS) {
                throw new ControlsViolation('invalid', 'The amount must be zero or more.');
            }

            if ($currency === null || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                throw new ControlsViolation('invalid', 'An amount needs a currency, for example ZAR.');
            }
        }

        $name = trim((string) ($data['name'] ?? ''));

        return [
            'subject_type' => $subject->value,
            'name' => $name !== '' ? mb_substr($name, 0, 160) : ucfirst($subject->plural()).($threshold !== null ? ' over '.Money::format($threshold, $currency) : '').' need approval',
            'threshold_cents' => $threshold,
            'currency' => $threshold !== null ? $currency : null,
            'approver_capability' => $capability->value,
            'min_role' => $minRole?->value,
            'steps_required' => $steps,
            'allow_self_approval' => (bool) ($data['allow_self_approval'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    private function authorise(User $actor, LedgerContext $context): void
    {
        $access = LedgerAccess::for($actor, $context) ?? throw new ControlsViolation('no_access', 'You do not belong to this ledger.');

        if (! $access->can(Capability::ManageApprovals)) {
            throw new ControlsViolation('missing_capability', 'Only an owner or finance manager can change approval policies.');
        }
    }

    private function sameLedger(ApprovalPolicy $policy, LedgerContext $context): void
    {
        if (! $policy->ledgerContext()->equals($context)) {
            throw new ControlsViolation('no_access', 'That policy belongs to another ledger.');
        }
    }
}
