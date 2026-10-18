<?php

namespace App\Policies;

use App\Models\Budget;
use App\Models\User;
use App\Support\Ledger\LedgerAccess;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * What a person may do with a budget: decided by LedgerAccess from the ledger the
 * budget belongs to (their own personal ledger, or a company they belong to) and
 * their role in it. Another ledger's budget never reaches here: the shared ledger
 * scope already 404s it.
 */
class BudgetPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return LedgerAccess::current($user)?->canRead() ?? false;
    }

    public function view(User $user, Budget $budget): bool
    {
        return LedgerAccess::forRow($user, $budget)?->canRead() ?? false;
    }

    public function create(User $user): bool
    {
        return LedgerAccess::current($user)?->canEditStructure() ?? false;
    }

    public function update(User $user, Budget $budget): bool
    {
        return LedgerAccess::forRow($user, $budget)?->canEditStructure() ?? false;
    }

    public function delete(User $user, Budget $budget): bool
    {
        return LedgerAccess::forRow($user, $budget)?->canDestroyStructure() ?? false;
    }
}
