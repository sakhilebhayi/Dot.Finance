<?php

namespace App\Support;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\FinanceAuditLog;
use App\Models\RecurringRule;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Knows what to record about each model, and writes the rows.
 *
 * "Values" fields are logged with before/after because they are amounts, dates,
 * ids, enums: numbers an auditor needs and that hold nothing personal. "Text"
 * fields (descriptions, notes, institution) are free text the user typed; only
 * the fact that they changed is logged, never the words.
 */
class FinanceAudit
{
    private const SUBJECTS = [
        Transaction::class => [
            'type' => 'transaction',
            'values' => ['account_id', 'category_id', 'amount_cents', 'currency', 'type', 'date', 'transfer_direction', 'transfer_group', 'recurring_rule_id'],
            'text' => ['description', 'notes'],
        ],
        Account::class => [
            'type' => 'account',
            'values' => ['name', 'type', 'currency', 'opening_balance_cents', 'opening_balance_date'],
            'text' => ['institution'],
        ],
        Category::class => [
            'type' => 'category',
            'values' => ['name', 'type', 'color', 'icon'],
            'text' => [],
        ],
        Budget::class => [
            'type' => 'budget',
            'values' => ['category_id', 'amount_cents', 'currency', 'month', 'year'],
            'text' => [],
        ],
        RecurringRule::class => [
            'type' => 'recurring_rule',
            'values' => ['account_id', 'category_id', 'amount_cents', 'currency', 'type', 'recurrence_type', 'recurrence_interval', 'start_date', 'next_occurrence_date', 'end_date', 'paused_at', 'ended_at'],
            'text' => ['description', 'notes'],
        ],
    ];

    private static int $muted = 0;

    private static ?string $contextOverride = null;

    /** Attach $context to every audit row written while $callback runs (e.g. the reason for an adjustment). */
    public static function withContext(string $context, callable $callback): mixed
    {
        $previous = self::$contextOverride;
        self::$contextOverride = $context;

        try {
            return $callback();
        } finally {
            self::$contextOverride = $previous;
        }
    }

    /** Run $callback without per-row observer logging (the CSV import logs one summary row instead). */
    public static function withoutRowLogging(callable $callback): mixed
    {
        self::$muted++;

        try {
            return $callback();
        } finally {
            self::$muted--;
        }
    }

    public static function isMuted(): bool
    {
        return self::$muted > 0;
    }

    public static function subjectType(Model $model): ?string
    {
        return self::SUBJECTS[$model::class]['type'] ?? null;
    }

    /** @return array{type: string, values: list<string>, text: list<string>}|null */
    public static function spec(Model $model): ?array
    {
        return self::SUBJECTS[$model::class] ?? null;
    }

    /**
     * Append one row. $changes is already shaped {field: {...}}.
     */
    public static function record(int $userId, string $action, string $subjectType, ?int $subjectId, ?string $label, ?array $changes = null, ?string $context = null, ?string $currency = null): FinanceAuditLog
    {
        $context ??= self::$contextOverride;

        return FinanceAuditLog::create([
            'user_id' => $userId,
            'action' => $action,
            'actor' => Auth::check() ? 'user' : 'system',
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'subject_label' => $label !== null ? mb_substr($label, 0, 255) : null,
            'currency' => $currency,
            'changes' => $changes === [] ? null : $changes,
            'context' => $context !== null ? mb_substr($context, 0, 255) : null,
        ]);
    }

    /**
     * A label for the audit page that never contains free text: accounts and
     * categories are named (the name is a label, not a memo); a transaction or
     * rule is described by its shape.
     */
    public static function label(Model $model): string
    {
        return match (true) {
            $model instanceof Account, $model instanceof Category => (string) $model->name,
            $model instanceof Transaction => ucfirst($model->type).' '.Money::format((int) $model->amount_cents, $model->currency).' on '.$model->date?->toDateString(),
            $model instanceof RecurringRule => ucfirst($model->recurrence_type).' '.$model->type.' '.Money::format((int) $model->amount_cents, $model->currency),
            $model instanceof Budget => 'Budget '.sprintf('%04d-%02d', $model->year, $model->month).' '.$model->currency,
            default => class_basename($model),
        };
    }
}
