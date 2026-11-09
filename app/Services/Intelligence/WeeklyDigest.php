<?php

namespace App\Services\Intelligence;

use App\Enums\Capability;
use App\Models\Intelligence\FinanceInsight;
use App\Support\Ledger\LedgerContext;

/**
 * The weekly summary's second half: after the findings are refreshed, one or two sentences on what needs
 * attention, appended to the same weekly summary row (so the unique key per ledger per ISO week is untouched) and
 * sent to the people who oversee the books as a "weekly_digest" notification.
 */
final class WeeklyDigest
{
    public function __construct(private readonly InsightEngine $engine, private readonly FinanceNotifier $notifier) {}

    /** @return list<string> sentences to append to the weekly summary; empty when nothing needs attention */
    public function prepare(LedgerContext $context): array
    {
        $this->engine->scan($context);

        $open = LedgerContext::run($context, fn () => FinanceInsight::query()->forContext($context)->visible()->get())
            ->sortByDesc(fn (FinanceInsight $f) => [$f->severityRank(), $f->last_seen_at?->timestamp])->values();

        if ($open->isEmpty()) {
            return [];
        }

        $top = $open->take(3)->map(fn (FinanceInsight $f) => $f->title)->implode('; ');
        $n = $open->count();

        return [($n === 1 ? '1 thing needs' : "{$n} things need").' a look in the books: '.$top.($n > 3 ? ' (and '.($n - 3).' more)' : '').'.'];
    }

    public function notify(LedgerContext $context, string $isoWeek, string $summary): int
    {
        $sent = 0;

        foreach ($this->notifier->recipientsWith($context, Capability::ViewAudit) as $user) {
            $note = $this->notifier->send($context, $user, 'weekly_digest', 'Your weekly summary for '.$context->label(), $summary, fx_route('insights.index'), 'digest:'.$isoWeek);
            $sent += $note ? 1 : 0;
        }

        return $sent;
    }
}
