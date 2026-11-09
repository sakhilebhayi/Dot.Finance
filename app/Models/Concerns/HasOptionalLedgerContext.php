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

    /** Global rows plus the rows of $context (null = global only), ignoring the ambient context. */
    public function scopeVisibleTo(Builder $query, ?LedgerContext $context): Builder
    {
        $query->withoutGlobalScope(GlobalOrLedgerContextScope::class);

        return GlobalOrLedgerContextScope::constrain($query, $context, $this->getTable());
    }

    public function scopeGlobalOnly(Builder $query): Builder
    {
        return $query->withoutGlobalScope(GlobalOrLedgerContextScope::class)->whereNull($this->getTable().'.user_id')->whereNull($this->getTable().'.team_id');
    }

    /** Only the rows this ledger owns (its overrides). */
    public function scopeOwnedBy(Builder $query, LedgerContext $context): Builder
    {
        $table = $this->getTable();
        $query->withoutGlobalScope(GlobalOrLedgerContextScope::class);

        return $context->isCompany()
            ? $query->where("{$table}.team_id", $context->teamId)
            : $query->where("{$table}.user_id", $context->userId)->whereNull("{$table}.team_id");
    }
}
