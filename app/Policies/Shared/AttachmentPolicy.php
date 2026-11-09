<?php

namespace App\Policies\Shared;

use App\Models\Shared\Attachment;
use App\Models\User;
use App\Support\Ledger\LedgerAccess;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Gate;

/**
 * A file is authorised through the record it belongs to: if you may not view the bill, you may not
 * open the bill's receipt. A file that is not attached to anything yet (waiting in an inbox) is
 * readable by anyone who can read the ledger. Being in the ledger is always required first.
 */
class AttachmentPolicy
{
    use HandlesAuthorization;

    public function view(User $user, Attachment $attachment): bool
    {
        if (! (LedgerAccess::forRow($user, $attachment)?->canRead() ?? false)) {
            return false;
        }

        $owner = $this->owningRecord($attachment);

        if ($owner === null) {
            return $attachment->attachable_type === null;
        }

        $policy = Gate::forUser($user)->getPolicyFor($owner);

        // A record with no policy cannot vouch for access: refuse rather than guess.
        return $policy !== null && Gate::forUser($user)->allows('view', $owner);
    }

    public function create(User $user): bool
    {
        return LedgerAccess::current($user)?->canRecordEntries() ?? false;
    }

    /** Record the outcome of reviewing an extraction draft. */
    public function review(User $user, Attachment $attachment): bool
    {
        return $this->view($user, $attachment) && (LedgerAccess::forRow($user, $attachment)?->canRecordEntries() ?? false);
    }

    private function owningRecord(Attachment $attachment): ?object
    {
        if ($attachment->attachable_type === null) {
            return null;
        }

        $relation = $attachment->attachable();

        // Archived parties and other soft-deleted owners still own their files.
        if (in_array(SoftDeletes::class, class_uses_recursive($relation->getRelated()), true)) {
            $relation->withTrashed();
        }

        return $relation->first();
    }
}
