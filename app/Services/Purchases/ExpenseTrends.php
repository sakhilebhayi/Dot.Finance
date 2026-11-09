<?php

namespace App\Services\Purchases;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalLine;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Month-on-month spending by expense account, read from the journal lines of the books (real postings only). It is the data
 * behind the dashboard panel "Where the money went": nothing is estimated, and a ledger without accounting has no trend.
 *
 * Year-end closing entries (and their reversals) are left out, so a closed year still reads as the year.
 */
final class ExpenseTrends
{
    /**
     * @return array{months: list<string>, rows: list<array{account_id: int, code: string, name: string, totals: array<string, int>, this_month: int, last_month: int, change_cents: int, change_pct: ?int}>, totals: array<string, int>}
     */
    public function monthly(LedgerContext $context, int $months = 6, ?CarbonImmutable $upTo = null): array
    {
        $upTo ??= CarbonImmutable::today();
        $first = $upTo->startOfMonth()->subMonths($months - 1);
        $keys = [];
        for ($i = 0; $i < $months; $i++) {
            $keys[] = $first->addMonths($i)->format('Y-m');
        }

        return LedgerContext::run($context, function () use ($first, $upTo, $keys) {
            $closing = JournalEntry::query()->where('source_type', 'period_close')->pluck('id');
            $excluded = $closing->merge(JournalEntry::query()->whereIn('reverses_entry_id', $closing)->pluck('id'));

            $accounts = GlAccount::query()->where('class', 'expense')->get()->keyBy('id');

            $lines = JournalLine::query()->whereIn('gl_account_id', $accounts->keys())
                ->where('entry_date', '>=', $first->toDateString())->where('entry_date', '<=', $upTo->endOfMonth()->toDateString())
                ->when($excluded->isNotEmpty(), fn ($q) => $q->whereNotIn('journal_entry_id', $excluded))
                ->get(['gl_account_id', 'entry_date', 'debit_cents', 'credit_cents']);

            $by = [];
            foreach ($lines as $line) {
                $month = $line->entry_date->format('Y-m');
                $by[$line->gl_account_id][$month] = ($by[$line->gl_account_id][$month] ?? 0) + ($line->debit_cents - $line->credit_cents);
            }

            $current = $keys[count($keys) - 1];
            $previous = $keys[count($keys) - 2] ?? $current;
            $rows = [];
            $totals = array_fill_keys($keys, 0);

            foreach ($by as $accountId => $perMonth) {
                $account = $accounts[$accountId];
                $row = array_fill_keys($keys, 0);
                foreach ($perMonth as $month => $net) {
                    $row[$month] = $net;
                    $totals[$month] += $net;
                }
                if (array_sum($row) === 0) {
                    continue;
                }
                $change = $row[$current] - $row[$previous];
                $rows[] = [
                    'account_id' => $accountId, 'code' => $account->code, 'name' => $account->name, 'totals' => $row, 'this_month' => $row[$current], 'last_month' => $row[$previous],
                    'change_cents' => $change, 'change_pct' => $row[$previous] > 0 ? (int) round($change * 100 / $row[$previous]) : null,
                ];
            }

            usort($rows, fn ($a, $b) => abs($b['change_cents']) <=> abs($a['change_cents']));

            return ['months' => $keys, 'rows' => $rows, 'totals' => $totals];
        });
    }

    /**
     * This month so far against the SAME NUMBER OF DAYS of last month (R-44 polish, D-1706). Nine days of November against all of
     * October always reads as a fall of 80% or more; the like-for-like days do not. On the last day of a month it is the whole month
     * against the whole of last month (capped at last month's length, so the 31st compares with a 30-day month's 30 days).
     *
     * @return array{days: int, this_from: string, this_to: string, last_from: string, last_to: string, rows: list<array{account_id: int, code: string, name: string, this_cents: int, last_cents: int, change_cents: int, change_pct: ?int}>}
     */
    public function likeForLike(LedgerContext $context, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $thisFrom = $today->startOfMonth();
        $lastFrom = $thisFrom->subMonthNoOverflow();
        $days = min($today->day, $lastFrom->daysInMonth);
        $lastTo = $lastFrom->addDays($days - 1);

        $rows = LedgerContext::run($context, function () use ($thisFrom, $today, $lastFrom, $lastTo) {
            $closing = JournalEntry::query()->where('source_type', 'period_close')->pluck('id');
            $excluded = $closing->merge(JournalEntry::query()->whereIn('reverses_entry_id', $closing)->pluck('id'));
            $accounts = GlAccount::query()->where('class', 'expense')->get()->keyBy('id');

            $sum = function (CarbonImmutable $from, CarbonImmutable $to) use ($accounts, $excluded): array {
                $by = [];
                $lines = JournalLine::query()->whereIn('gl_account_id', $accounts->keys())
                    ->where('entry_date', '>=', $from->toDateString())->where('entry_date', '<=', $to->toDateString())
                    ->when($excluded->isNotEmpty(), fn ($q) => $q->whereNotIn('journal_entry_id', $excluded))
                    ->get(['gl_account_id', 'debit_cents', 'credit_cents']);
                foreach ($lines as $line) {
                    $by[$line->gl_account_id] = ($by[$line->gl_account_id] ?? 0) + ($line->debit_cents - $line->credit_cents);
                }

                return $by;
            };

            $now = $sum($thisFrom, $today);
            $before = $sum($lastFrom, $lastTo);
            $out = [];
            foreach (array_unique(array_merge(array_keys($now), array_keys($before))) as $id) {
                $a = $accounts[$id];
                $this_ = $now[$id] ?? 0;
                $last = $before[$id] ?? 0;
                if ($this_ === 0 && $last === 0) {
                    continue;
                }
                $change = $this_ - $last;
                $out[] = [
                    'account_id' => (int) $id, 'code' => $a->code, 'name' => $a->name, 'this_cents' => $this_, 'last_cents' => $last,
                    'change_cents' => $change, 'change_pct' => $last > 0 ? intdiv($change * 100 + intdiv($last, 2) * ($change < 0 ? -1 : 1), $last) : null,
                ];
            }
            usort($out, fn ($a, $b) => abs($b['change_cents']) <=> abs($a['change_cents']));

            return $out;
        });

        return ['days' => $days, 'this_from' => $thisFrom->toDateString(), 'this_to' => $today->toDateString(), 'last_from' => $lastFrom->toDateString(), 'last_to' => $lastTo->toDateString(), 'rows' => $rows];
    }

    /** The biggest month-on-month moves, for a short panel. @return Collection<int, array<string, mixed>> */
    public function movers(LedgerContext $context, int $limit = 5): Collection
    {
        return collect($this->monthly($context)['rows'])->filter(fn ($r) => $r['change_cents'] !== 0)->take($limit)->values();
    }

    /** The biggest like-for-like moves (see likeForLike), for the short panel. @return Collection<int, array<string, mixed>> */
    public function sameDayMovers(LedgerContext $context, int $limit = 5, ?CarbonImmutable $today = null): Collection
    {
        return collect($this->likeForLike($context, $today)['rows'])->filter(fn ($r) => $r['change_cents'] !== 0)->take($limit)->values();
    }
}
