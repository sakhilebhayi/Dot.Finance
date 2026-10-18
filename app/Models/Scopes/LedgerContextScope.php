<?php

namespace App\Models\Scopes;

use App\Support\Ledger\LedgerContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * The one scope every ledger model shares (it replaces the old per-user scope).
 *
 *   personal context -> user_id = me AND team_id IS NULL
 *   company context  -> team_id = that company (and I belong to it: see LedgerContextManager)
 *
 * It FAILS CLOSED. No signed-in person, no context, a company the person does not
 * belong to, a value that is not a context: all return no rows. The only way to see
 * rows without a signed-in person is to name a context on purpose, with
 * LedgerContext::run(), or to query one explicitly with Model::forContext().
 *
 * Route-model binding goes through this scope too, so another context's row 404s
 * before any policy runs.
 */
final class LedgerContextScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = LedgerContext::current();
        $table = $model->getTable();

        if ($context === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        if ($context->isCompany()) {
            $builder->where($table.'.team_id', $context->teamId);

            return;
        }

        $builder->where($table.'.user_id', $context->userId)->whereNull($table.'.team_id');
    }
}
