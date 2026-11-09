<?php

namespace App\Models\Concerns;

use App\Models\Scopes\GlobalOrLedgerContextScope;
use App\Support\Ledger\LedgerContext;
use App\Support\Ledger\LedgerContextViolation;
use Illuminate\Database\Eloquent\Builder;

/**
 * For reference rows that are global (no owner) or an override owned by exactly one
 * ledger. Reads see the global rows plus the active ledger's own (never another
 * ledger's); a row never has two owners and never moves between owners.
 */
trait HasOptionalLedgerContext
{
    protected static function bootHasOptionalLedgerContext(): void
    {
        static::addGlobalScope(new GlobalOrLedgerContextScope);

        static::saving(function ($model) {
            if ($model->user_id !== null && $model->team_id !== null) {
                throw new LedgerContextViolation(class_basename($model).': a row has at most one owner (a user or a company), never both.');
            }

            if ($model->exists && ($model->isDirty('user_id') || $model->isDirty('team_id'))) {
                throw new LedgerContextViolation(class_basename($model).': a row cannot be moved to another owner.');
            }
        });
    }

    public function isGlobal(): bool
    {
        return $this->user_id === null && $this->team_id === null;
    }

    /**
     * Global rows plus the rows of $context (null = global only), ignoring the ambient context. The one
     * sanctioned way past GlobalOrLedgerContextScope: it always names a context.
     */
    public static function visibleTo(?LedgerContext $context): Builder
    {
        $model = new static;

        return GlobalOrLedgerContextScope::constrain($model->newQueryWithoutScope(GlobalOrLedgerContextScope::class), $context, $model->getTable());
    }

    /** Only global rows. */
    public static function globalOnly(): Builder
    {
        $model = new static;
        $table = $model->getTable();

        return $model->newQueryWithoutScope(GlobalOrLedgerContextScope::class)->whereNull("{$table}.user_id")->whereNull("{$table}.team_id");
    }

    /** Only the rows this ledger owns (its overrides). */
    public static function ownedBy(LedgerContext $context): Builder
    {
        $model = new static;
        $table = $model->getTable();
        $query = $model->newQueryWithoutScope(GlobalOrLedgerContextScope::class);

        return $context->isCompany()
            ? $query->where("{$table}.team_id", $context->teamId)
            : $query->where("{$table}.user_id", $context->userId)->whereNull("{$table}.team_id");
    }

    /** Every row of every owner, for integrity checks (overlapping versions) that must see the whole table. Never for display. */
    public static function everyRow(): Builder
    {
        return (new static)->newQueryWithoutScope(GlobalOrLedgerContextScope::class);
    }
}
