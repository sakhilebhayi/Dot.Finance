<?php

namespace App\Models\Concerns;

use App\Support\Shared\Exceptions\VersionedRowViolation;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Rule rows that are versioned by date and append-only.
 *
 * What the model enforces, so no caller has to remember:
 *  - a row's content never changes after it is created. Only the fields named by
 *    mutableColumns() may change (for a rule: valid_to, to close it, and review
 *    bookkeeping), and valid_to may only move earlier, never later;
 *  - a row is never deleted;
 *  - two versions of the same series (same identity columns and the same owner) never
 *    cover the same day.
 *
 * The using model supplies versionIdentity() (the columns that name a series) and
 * mutableColumns().
 */
trait AppendsVersions
{
    /** @return list<string> columns that together name one series of versions */
    abstract public function versionIdentity(): array;

    /** @return list<string> columns that may change after creation */
    public function mutableColumns(): array
    {
        return ['valid_to', 'reviewed_by', 'review_due'];
    }

    protected static function bootAppendsVersions(): void
    {
        static::creating(function ($model) {
            $model->assertNoOverlap();
        });

        static::updating(function ($model) {
            $allowed = array_merge($model->mutableColumns(), ['updated_at']);

            foreach (array_keys($model->getDirty()) as $column) {
                if (! in_array($column, $allowed, true)) {
                    throw new VersionedRowViolation(class_basename($model).": {$column} cannot be changed after the row is created. Add a new version instead (a new row with a later valid_from).");
                }
            }

            if ($model->isDirty('valid_to')) {
                $old = $model->getOriginal('valid_to');
                $new = $model->valid_to;
                if ($new === null) {
                    throw new VersionedRowViolation(class_basename($model).': valid_to cannot be reopened once set.');
                }
                if ($old !== null && $new->gt(Carbon::parse(substr((string) $old, 0, 10)))) {
                    throw new VersionedRowViolation(class_basename($model).': valid_to can only move earlier, never later.');
                }
                $model->assertNoOverlap();
            }
        });

        static::deleting(function ($model) {
            throw new VersionedRowViolation(class_basename($model).': versioned rows are never deleted. Close the row with valid_to and add a new version.');
        });
    }

    /** Whether this version covers $date (inclusive on both ends). */
    public function coversDate(CarbonInterface $date): bool
    {
        $day = $date->toDateString();

        return $this->valid_from->toDateString() <= $day && ($this->valid_to === null || $this->valid_to->toDateString() >= $day);
    }

    public function assertNoOverlap(): void
    {
        $query = static::query()->withoutGlobalScopes();

        foreach ($this->versionIdentity() as $column) {
            $query->where($column, $this->getAttribute($column));
        }

        $query->when($this->user_id === null, fn (Builder $q) => $q->whereNull('user_id'), fn (Builder $q) => $q->where('user_id', $this->user_id));
        $query->when($this->team_id === null, fn (Builder $q) => $q->whereNull('team_id'), fn (Builder $q) => $q->where('team_id', $this->team_id));

        if ($this->exists) {
            $query->where($this->getKeyName(), '!=', $this->getKey());
        }

        $from = $this->valid_from->toDateString();
        $to = $this->valid_to?->toDateString();

        $query->where(function (Builder $q) use ($from) {
            $q->whereNull('valid_to')->orWhere('valid_to', '>=', $from);
        });

        if ($to !== null) {
            $query->where('valid_from', '<=', $to);
        }

        if ($query->exists()) {
            throw new VersionedRowViolation(class_basename($this).': another version of the same rule already covers part of '.$from.($to ? " to {$to}" : ' onward').'. Close the earlier version first.');
        }
    }
}
