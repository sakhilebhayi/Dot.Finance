<?php

namespace App\Models\Scopes;

use App\Support\Ledger\LedgerContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * The scope for reference data that is global by default and may be overridden per ledger
 * (tax codes, tax rules, tax rates, FX rates).
 *
 *   rows with no owner (global)  -> always visible
 *   rows owned by the active ledger -> visible
 *   rows owned by any other ledger  -> never visible
 *
 * It fails closed like LedgerContextScope: with no active context only global rows show.
 */
final class GlobalOrLedgerContextScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where(fn (Builder $q) => self::constrain($q, LedgerContext::current(), $model->getTable()));
    }

    /** The visibility condition itself, for callers that name a context explicitly. */
    public static function constrain(Builder $query, ?LedgerContext $context, string $table): Builder
    {
        $global = fn (Builder $q) => $q->whereNull("{$table}.user_id")->whereNull("{$table}.team_id");

        if ($context === null) {
            return $query->where($global);
        }

        return $query->where(function (Builder $q) use ($context, $table, $global) {
            $q->where($global);
            $context->isCompany()
                ? $q->orWhere("{$table}.team_id", $context->teamId)
                : $q->orWhere(fn (Builder $own) => $own->where("{$table}.user_id", $context->userId)->whereNull("{$table}.team_id"));
        });
    }
}
