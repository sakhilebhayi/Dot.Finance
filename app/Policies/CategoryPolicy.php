<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;
use App\Support\Ledger\LedgerAccess;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * What a person may do with a category: decided by LedgerAccess from the ledger the
 * category belongs to (their own personal ledger, or a company they belong to) and
 * their role in it. Another ledger's category never reaches here: the shared ledger
 * scope already 404s it.
 */
class CategoryPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return LedgerAccess::current($user)?->canRead() ?? false;
    }

    public function view(User $user, Category $category): bool
    {
        return LedgerAccess::forRow($user, $category)?->canRead() ?? false;
    }

    public function create(User $user): bool
    {
        return LedgerAccess::current($user)?->canEditStructure() ?? false;
    }

    public function update(User $user, Category $category): bool
    {
        return LedgerAccess::forRow($user, $category)?->canEditStructure() ?? false;
    }

    public function delete(User $user, Category $category): bool
    {
        return LedgerAccess::forRow($user, $category)?->canDestroyStructure() ?? false;
    }
}
