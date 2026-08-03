<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Dot.Finance is single-user, not team-scoped (see AccountPolicy). Every
 * model that owns a user_id column applies this trait so a query against
 * it is scoped to the authenticated user by default, the same way
 * Dot.Mines' HasTeamFilters scopes every tenant-owned model to the
 * current team — the goal is that a forgotten where('user_id', ...) call
 * in a future controller can no longer leak another user's rows, because
 * the model itself never returns unscoped results while a user is
 * authenticated.
 *
 * mass-assignment still sets user_id explicitly at create time (see each
 * controller's store()); this scope only governs reads.
 */
trait HasUserScope
{
    protected static function bootHasUserScope(): void
    {
        static::addGlobalScope('user', function (Builder $builder): void {
            if (Auth::check()) {
                $builder->where($builder->getModel()->getTable().'.user_id', Auth::id());
            }
        });
    }
}
