<?php

namespace App\Services\Tax;

use App\Contracts\Intelligence\TaxDeadlineSource;
use App\Models\Tax\TaxDeadline;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The Tax module's calendar as the notification rules and the assistant read it: the deadlines that still need doing
 * (open or prepared) with a due date inside a window. Reads the stored calendar only; nothing is generated here.
 */
final class TaxDeadlines implements TaxDeadlineSource
{
    /** @return list<array{key: string, label: string, due_on: string, href: ?string}> */
    public function dueBetween(LedgerContext $context, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->rows($context, $from, $to, false)->map(fn (TaxDeadline $d) => [
            'key' => 'tax-'.$d->obligation.'-'.$d->id,
            'label' => $this->label($d),
            'due_on' => $d->due_date->toDateString(),
            'href' => fx_route('tax.calendar.show', [$d->id]),
        ])->all();
    }

    /**
     * For the assistant ("what tax do I need to prepare for?"): null when the ledger has no calendar at all.
     *
     * @return list<array{label: string, due_on: string, href: ?string, note: ?string}>|null
     */
    public function upcoming(LedgerContext $context, CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        if (! TaxDeadline::query()->forContext($context)->exists()) {
            return null;
        }

        return $this->rows($context, $from, $to, true)->map(fn (TaxDeadline $d) => [
            'label' => $this->label($d),
            'due_on' => $d->due_date->toDateString(),
            'href' => fx_route('tax.calendar.show', [$d->id]),
            'note' => $d->adjustment_note ?: ($d->status === 'prepared' ? 'Prepared, not yet recorded as submitted.' : null),
        ])->all();
    }

    /** @return Collection<int, TaxDeadline> */
    private function rows(LedgerContext $context, CarbonImmutable $from, CarbonImmutable $to, bool $withOverdue)
    {
        // an overdue, unfiled deadline is still something to do: the assistant lists it, the reminder rules (which only
        // count down to a date) do not
        return TaxDeadline::query()->forContext($context)->whereIn('status', ['open', 'prepared'])
            ->where('due_date', '<=', $to->toDateString())
            ->when(! $withOverdue, fn ($q) => $q->where('due_date', '>=', $from->toDateString()))
            ->orderBy('due_date')->orderBy('id')->get();
    }

    private function label(TaxDeadline $d): string
    {
        return $d->label().($d->period_label ? ' for '.$d->period_label : '');
    }
}
