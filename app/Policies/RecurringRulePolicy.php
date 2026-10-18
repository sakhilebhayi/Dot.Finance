<?php

namespace App\Policies;

use App\Models\RecurringRule;
use App\Models\User;
use App\Support\Ledger\LedgerAccess;

/** Recurring rules follow the same rule as transactions: whoever may record entries may manage the schedule. */
class RecurringRulePolicy
{
    public function viewAny(User $user): bool
    {
        return LedgerAccess::current($user)?->canRead() ?? false;
    }

    public function view(User $user, RecurringRule $rule): bool
    {
        return LedgerAccess::forRow($user, $rule)?->canRead() ?? false;
    }

    public function update(User $user, RecurringRule $rule): bool
    {
        return LedgerAccess::forRow($user, $rule)?->canRecordEntries() ?? false;
    }

    public function delete(User $user, RecurringRule $rule): bool
    {
        return LedgerAccess::forRow($user, $rule)?->canRecordEntries() ?? false;
    }
}
