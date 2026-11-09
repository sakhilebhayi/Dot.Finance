<?php

namespace App\Services\Reporting;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalEntry;
use App\Models\Shared\Payment;
use App\Models\Shared\PaymentAllocation;
use App\Support\Ledger\LedgerContext;
use Illuminate\Support\Facades\Cache;

/**
 * Caches the expensive aggregates of a report against the state of the books, never against the clock.
 *
 * The key carries a fingerprint of everything an aggregate is computed from: the last journal entry id (every posting,
 * reversal and year-end close adds one), the chart (a re-classified account changes what a report groups), and the
 * payment and allocation counters the cash basis reads. Any change gives a different key, so a cached figure can be
 * unused but never stale. Keys are per ledger, so one ledger can never be served another's numbers.
 */
class ReportCache
{
    /** The id of the newest journal entry in the ledger: the journal sequence. */
    public function journalSequence(LedgerContext $context): int
    {
        return (int) JournalEntry::query()->forContext($context)->max('id');
    }

    public function fingerprint(LedgerContext $context): string
    {
        $accounts = GlAccount::query()->forContext($context);
        $allocations = PaymentAllocation::query()->forContext($context);
        $payments = Payment::query()->forContext($context);

        return implode('.', [
            $this->journalSequence($context),
            (clone $accounts)->count(), (string) (clone $accounts)->max('updated_at'),
            (int) (clone $allocations)->max('id'), (clone $allocations)->whereNotNull('released_at')->count(),
            (int) (clone $payments)->max('id'), (clone $payments)->where('status', 'reversed')->count(),
        ]);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $compute
     * @return T
     */
    public function remember(ReportRequest $request, string $name, array $parts, callable $compute): mixed
    {
        return Cache::remember('fin-report:'.md5($request->cacheKey($name, $parts)), (int) config('reporting.cache_ttl_seconds'), $compute);
    }
}
