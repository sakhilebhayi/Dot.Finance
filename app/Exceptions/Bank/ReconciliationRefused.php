<?php

namespace App\Exceptions\Bank;

use RuntimeException;

/**
 * A reconciliation rule said no. $reason is a stable machine code (tests and controllers branch on it), the message is for
 * a person:
 *
 *   not_draft, already_open, nothing_to_match, not_in_ledger, already_matched, totals_differ, out_of_scope, too_many,
 *   difference, unmatched_lines, bad_date, needs_approval, not_newest, no_reason, locked, unavailable, invalid.
 */
class ReconciliationRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
