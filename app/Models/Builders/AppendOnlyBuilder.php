<?php

namespace App\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * An Eloquent builder that can read and insert but never rewrite or remove.
 *
 * Model events do not fire for query-builder updates and deletes, so refusing
 * only in the model would leave `FinanceAuditLog::query()->update(...)` open.
 * (Raw DB::table() access is outside what any Eloquent guard can stop; the
 * only real defence there is database permissions, noted in the PR.)
 */
class AppendOnlyBuilder extends Builder
{
    private function refuse(): never
    {
        throw new LogicException('The finance audit log is append-only: rows can be added but never changed or removed.');
    }

    public function update(array $values)
    {
        $this->refuse();
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        $this->refuse();
    }

    public function delete()
    {
        $this->refuse();
    }

    public function forceDelete()
    {
        $this->refuse();
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        $this->refuse();
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        $this->refuse();
    }
}
