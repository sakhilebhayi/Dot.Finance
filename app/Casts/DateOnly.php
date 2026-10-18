<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A calendar date, stored as a bare "Y-m-d" on every driver.
 *
 * Eloquent's built-in `date` cast writes "Y-m-d 00:00:00", which SQLite keeps
 * as a string. Comparing that against a bare "Y-m-d" string (or against another
 * column written the other way) silently gets "<=" wrong on the same day. A
 * ledger compares dates constantly (opening-balance cut-offs, budget months,
 * recurrence due dates), so dates here are always stored the same way.
 */
class DateOnly implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse(substr((string) $value, 0, 10))->startOfDay();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->format('Y-m-d');
        }

        return Carbon::parse((string) $value)->format('Y-m-d');
    }
}
