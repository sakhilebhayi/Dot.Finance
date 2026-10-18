<?php

namespace App\Observers;

use App\Models\Account;
use App\Models\Transaction;
use App\Support\FinanceAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes the audit trail for every create, update, delete and restore of the
 * ledger models. Registered once for all of them (see AppServiceProvider).
 */
class FinanceAuditObserver
{
    public function created(Model $model): void
    {
        $this->write($model, 'created', $this->snapshot($model, after: true));
    }

    public function updated(Model $model): void
    {
        $spec = FinanceAudit::spec($model);
        $changes = [];

        foreach ($model->getChanges() as $field => $after) {
            if (in_array($field, $spec['values'], true)) {
                $changes[$field] = ['before' => $this->plain($model->getOriginal($field)), 'after' => $this->plain($model->getAttribute($field))];
            } elseif (in_array($field, $spec['text'], true)) {
                $changes[$field] = ['changed' => true];
            }
        }

        // Soft delete / restore also touch deleted_at (and updated_at); they are
        // logged by deleted() and restored(), not as an edit.
        if ($changes === []) {
            return;
        }

        $action = 'updated';
        if ($model instanceof Account && (isset($changes['opening_balance_cents']) || isset($changes['opening_balance_date']))) {
            $action = 'opening_balance_adjusted';
        }

        $this->write($model, $action, $changes);
    }

    public function deleted(Model $model): void
    {
        $this->write($model, method_exists($model, 'isForceDeleting') && $model->isForceDeleting() ? 'purged' : 'deleted', $this->snapshot($model, after: false));
    }

    public function restored(Model $model): void
    {
        $this->write($model, 'restored', null);
    }

    private function write(Model $model, string $action, ?array $changes): void
    {
        if (FinanceAudit::isMuted()) {
            return;
        }

        $context = null;
        if ($model instanceof Transaction) {
            $context = match (true) {
                $model->transfer_group !== null => 'transfer:'.$model->transfer_group,
                $model->recurring_rule_id !== null && ! Auth::check() => 'recurring:'.$model->recurring_rule_id,
                default => null,
            };
        }

        FinanceAudit::record(
            $model->ledgerOwner(),
            $action,
            FinanceAudit::subjectType($model),
            (int) $model->getKey(),
            FinanceAudit::label($model),
            $changes,
            $context,
            $model->getAttribute('currency'),
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function snapshot(Model $model, bool $after): array
    {
        $spec = FinanceAudit::spec($model);
        $key = $after ? 'after' : 'before';
        $out = [];

        foreach ($spec['values'] as $field) {
            $value = $this->plain($model->getAttribute($field));
            if ($value !== null) {
                $out[$field] = [$key => $value];
            }
        }

        foreach ($spec['text'] as $field) {
            if ($model->getAttribute($field) !== null && $model->getAttribute($field) !== '') {
                $out[$field] = ['changed' => true];
            }
        }

        return $out;
    }

    private function plain(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return $value;
    }
}
