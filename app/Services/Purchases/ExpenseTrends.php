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
                // summed per account and day in the database (a busy ledger has tens of thousands of expense lines in six months;
                // loading each as a model took 200 MB and a second), then folded into months here
                ->groupBy('gl_account_id', 'entry_date')->selectRaw('gl_account_id, entry_date, SUM(debit_cents) AS debits, SUM(credit_cents) AS credits')
                ->toBase()->get();

            $by = [];
            foreach ($lines as $line) {
                $month = substr((string) $line->entry_date, 0, 7);
                $by[(int) $line->gl_account_id][$month] = ($by[(int) $line->gl_account_id][$month] ?? 0) + ((int) $line->debits - (int) $line->credits);
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

    /** The biggest month-on-month moves, for a short panel. @return Collection<int, array<string, mixed>> */
    public function movers(LedgerContext $context, int $limit = 5): Collection
    {
        return collect($this->monthly($context)['rows'])->filter(fn ($r) => $r['change_cents'] !== 0)->take($limit)->values();
    }
}
