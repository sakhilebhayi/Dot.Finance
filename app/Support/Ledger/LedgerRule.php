<?php

namespace App\Support\Ledger;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Validation rules that only see records inside one ledger, so an id smuggled from
 * another person's or another company's ledger fails validation instead of being
 * written. The raw-table `exists` rule bypasses the model scope, so it has to carry
 * the owner condition itself.
 */
final class LedgerRule
{
    /** An `exists:{table},id` rule limited to rows owned by $context (deleted rows are not excluded: add ->whereNull('deleted_at') where they must be). */
    public static function exists(string $table, LedgerContext $context): Exists
    {
        return Rule::exists($table, 'id')->where(function ($query) use ($context, $table) {
            $context->isCompany()
                ? $query->where("{$table}.team_id", $context->teamId)
                : $query->where("{$table}.user_id", $context->userId)->whereNull("{$table}.team_id");
        });
    }
}
