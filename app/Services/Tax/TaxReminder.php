<?php

namespace App\Services\Tax;

use App\Models\Tax\TaxDeadline;
use App\Models\Tax\TaxReminderNotice;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/**
 * Creates reminder RECORDS for upcoming and overdue deadlines. It sends nothing: the Intelligence module reads the rows
 * whose delivered_at is NULL (TaxReminderNotice::query()->whereNull('delivered_at')) and delivers them by e-mail, bell or
 * Dot.Notify, then stamps delivered_at and delivered_via.
 *
 * Idempotent: one row per (deadline, kind). The offsets come from the rule book (tax.reminder.days_before, a Dot.Finance
 * policy). A deadline that is submitted, paid or waived gets no new reminders and its undelivered ones are dismissed.
 */
final class TaxReminder
{
    public function __construct(private readonly TaxRules $rules) {}

    /** @return array{created: int, dismissed: int} */
    public function refresh(LedgerContext $ctx, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $offsets = $this->rules->session($ctx)->rule('tax.reminder.days_before', $today)->json();
        $created = 0;
        $dismissed = 0;

        $deadlines = TaxDeadline::query()->forContext($ctx)
            ->where('due_date', '>=', $today->subDays(60)->toDateString())->get();

        foreach ($deadlines as $deadline) {
            if (in_array($deadline->status, ['submitted', 'paid', 'waived'], true)) {
                $dismissed += $this->dismissFor($ctx, $deadline);

                continue;
            }

            $due = CarbonImmutable::parse($deadline->due_date->toDateString());

            foreach ($offsets as $days) {
                $remindOn = $due->subDays((int) $days);
                if ($remindOn->gt($today)) {
                    continue;
                }
                // an old offset that passed before the deadline existed is not worth sending (only the nearest one is)
                if ($remindOn->lt($today->subDays(1)) && $days > 0 && $due->gte($today) && $this->nextOffsetPassed($due, $offsets, (int) $days, $today)) {
                    continue;
                }
                $created += $this->make($ctx, $deadline, 'd'.(int) $days, $remindOn, (int) $days === 0 ? 'warning' : 'info', $this->wording($deadline, $due, $today));
            }

            if ($today->gt($due)) {
                $created += $this->make($ctx, $deadline, 'overdue', $due->addDay(), 'overdue', $this->wording($deadline, $due, $today));
            }
        }

        return ['created' => $created, 'dismissed' => $dismissed];
    }

    public function dismissFor(LedgerContext $ctx, TaxDeadline $deadline): int
    {
        return TaxReminderNotice::query()->forContext($ctx)->where('tax_deadline_id', $deadline->id)
            ->whereNull('delivered_at')->whereNull('dismissed_at')->update(['dismissed_at' => now()]);
    }

    /** Reminder records still waiting for delivery, oldest first. @return \Illuminate\Support\Collection<int, TaxReminderNotice> */
    public function undelivered(LedgerContext $ctx)
    {
        return TaxReminderNotice::query()->forContext($ctx)->whereNull('delivered_at')->whereNull('dismissed_at')->orderBy('remind_on')->get();
    }

    private function nextOffsetPassed(CarbonImmutable $due, array $offsets, int $days, CarbonImmutable $today): bool
    {
        // a closer offset (fewer days before) that has also passed makes this older one stale
        foreach ($offsets as $other) {
            if ((int) $other < $days && $due->subDays((int) $other)->lte($today)) {
                return true;
            }
        }

        return false;
    }

    private function make(LedgerContext $ctx, TaxDeadline $deadline, string $kind, CarbonImmutable $remindOn, string $severity, array $text): int
    {
        $exists = TaxReminderNotice::query()->forContext($ctx)->where('tax_deadline_id', $deadline->id)->where('kind', $kind)->exists();
        if ($exists) {
            return 0;
        }

        TaxReminderNotice::query()->create($ctx->ownerAttributes() + [
            'tax_deadline_id' => $deadline->id, 'kind' => $kind, 'remind_on' => $remindOn->toDateString(),
            'title' => $text['title'], 'body' => $text['body'], 'severity' => $severity,
        ]);

        return 1;
    }

    /** @return array{title: string, body: string} */
    private function wording(TaxDeadline $d, CarbonImmutable $due, CarbonImmutable $today): array
    {
        $days = $today->diffInDays($due, false);
        $when = match (true) {
            $days > 1 => "is due in {$days} days, on {$due->format('j F Y')}",
            $days === 1 => "is due tomorrow, {$due->format('j F Y')}",
            $days === 0 => "is due today, {$due->format('j F Y')}",
            default => 'was due on '.$due->format('j F Y').' ('.abs($days).' day'.(abs($days) === 1 ? '' : 's').' ago)',
        };
        $state = $d->status === 'prepared' ? 'It is prepared but not yet recorded as submitted.' : 'It has not been prepared yet.';

        return [
            'title' => $d->label().': '.$d->period_label.' '.($days < 0 ? 'is overdue' : 'is coming up'),
            'body' => "{$d->label()} for {$d->period_label} {$when}. {$state} Dot.Finance does not file anything with SARS: a person files and records it here.",
        ];
    }
}
