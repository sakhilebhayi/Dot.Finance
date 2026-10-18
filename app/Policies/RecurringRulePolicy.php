<?php

namespace App\Policies;

use App\Models\RecurringRule;
use App\Models\User;

class RecurringRulePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, RecurringRule $rule): bool
    {
        return $user->id === $rule->user_id;
    }

    public function update(User $user, RecurringRule $rule): bool
    {
        return $user->id === $rule->user_id;
    }
}
