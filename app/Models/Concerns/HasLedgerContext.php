<?php

namespace App\Models\Concerns;

use App\Models\Scopes\LedgerContextScope;
use App\Support\Ledger\LedgerContext;
use App\Support\Ledger\LedgerOwnership;
use Illuminate\Database\Eloquent\Builder;

/**
 * Makes a model a ledger row: it belongs to exactly one owner context (user_id for a
 * person, team_id for a company), reads are confined to the active context by
 * LedgerContextScope, and every save is checked by LedgerOwnership (one owner, never
 * moved, never tied to a record in another context).
 *
 * Mass assignment still sets the owner explicitly at create time (each model lists user_id
 * and team_id in $fillable), except where a row inherits it from its parent (a transaction
 * or recurring rule takes its account's).
 */
trait HasLedgerContext
{
    protected static function bootHasLedgerContext(): void
    {
        static::addGlobalScope(new LedgerContextScope);

        static::saving(fn ($model) => LedgerOwnership::guard($model));
    }

    /** The context this row belongs to. */
    public function ledgerContext(): LedgerContext
    {
        return LedgerContext::fromOwner(
            $this->user_id !== null ? (int) $this->user_id : null,
            $this->team_id !== null ? (int) $this->team_id : null,
        );
    }

    /** @return array{user_id: ?int, team_id: ?int} */
    public function ledgerOwner(): array
    {
        return [
            'user_id' => $this->user_id !== null ? (int) $this->user_id : null,
            'team_id' => $this->team_id !== null ? (int) $this->team_id : null,
        ];
    }

    /**
     * Query one context explicitly, ignoring the ambient one. For services that are handed a
     * context (reports, the scheduler); the context must come from LedgerContextManager or
     * LedgerContext::run(), never from request input.
     */
    public function scopeForContext(Builder $query, LedgerContext $context): Builder
    {
        $table = $this->getTable();
        $query->withoutGlobalScope(LedgerContextScope::class);

        return $context->isCompany()
            ? $query->where($table.'.team_id', $context->teamId)
            : $query->where($table.'.user_id', $context->userId)->whereNull($table.'.team_id');
    }

    /**
     * The column that decides this row's context when none is given (a transaction's account),
     * or null for a row that always states its own.
     */
    public function ledgerInheritsFrom(): ?string
    {
        return null;
    }

    /**
     * Foreign keys that must point at a row in the same context: column => model class.
     *
     * @return array<string, class-string>
     */
    public function ledgerReferences(): array
    {
        return [];
    }
}
