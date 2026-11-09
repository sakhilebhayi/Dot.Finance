<?php

namespace App\Exceptions\Controls;

use RuntimeException;

/**
 * A governance rule said no. $reason is a stable machine code (tests and controllers branch on it),
 * the message is for a person:
 *
 *   no_access, missing_capability, role_too_low, separation_of_duties, over_limit, stale_amount,
 *   not_pending, already_decided, subject_gone, no_policy, above_infodot_role, above_own_role,
 *   not_a_member, personal_ledger, owner_role, invalid.
 */
final class ControlsViolation extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    /** Whether the person should see this as "not allowed" (403) rather than "check your input" (422). */
    public function isDenial(): bool
    {
        return in_array($this->reason, ['no_access', 'missing_capability', 'role_too_low', 'separation_of_duties', 'over_limit', 'above_own_role', 'personal_ledger'], true);
    }
}
