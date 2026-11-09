<?php

namespace App\Services\Controls;

use App\Enums\Capability;
use App\Exceptions\Controls\ControlsViolation;
use App\Models\Controls\AccessReview;
use App\Models\User;
use App\Support\FinanceAudit;
use App\Support\Ledger\LedgerAccess;
use App\Support\Ledger\LedgerContext;
use Illuminate\Support\Facades\DB;

/** Recording that someone reviewed who has access: the confirmation, with the roster as it stood. */
final class AccessReviews
{
    public function __construct(private readonly AccessReport $report) {}

    /** @throws ControlsViolation */
    public function record(User $reviewer, LedgerContext $context, ?string $notes = null): AccessReview
    {
        $access = LedgerAccess::for($reviewer, $context) ?? throw new ControlsViolation('no_access', 'You do not belong to this ledger.');

        if (! $access->can(Capability::ManageMembers)) {
            throw new ControlsViolation('missing_capability', 'Only an owner or finance manager can confirm an access review.');
        }

        return DB::transaction(function () use ($reviewer, $context, $notes) {
            $rows = $this->report->rows($context);

            $review = AccessReview::create($context->ownerAttributes() + [
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'notes' => $notes !== null && trim($notes) !== '' ? mb_substr(trim($notes), 0, 2000) : null,
                'member_count' => $rows->count(),
                'snapshot' => $rows->map(fn (array $row) => [
                    'user_id' => $row['user_id'], 'name' => $row['name'], 'email' => $row['email'],
                    'infodot_role' => $row['infodot_role'], 'role' => $row['role']->value, 'local_role' => $row['local_role']?->value,
                    'granted_by' => $row['granted_by'], 'granted_at' => $row['granted_at']?->toIso8601String(),
                ])->all(),
            ]);

            FinanceAudit::record(
                $context->ownerAttributes(), 'confirmed', 'access_review', $review->id, $review->auditLabel(),
                ['member_count' => ['after' => $review->member_count]] + ($review->notes !== null ? ['notes' => ['changed' => true]] : []),
                null, null, $reviewer->id,
            );

            return $review;
        });
    }

    public function latest(LedgerContext $context): ?AccessReview
    {
        return AccessReview::query()->forContext($context)->with('reviewer:id,name')->latest('reviewed_at')->latest('id')->first();
    }
}
