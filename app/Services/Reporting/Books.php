<?php

namespace App\Services\Reporting;

use App\Enums\ReportBasis;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalEntry;
use App\Services\Accounting\GlQuery;
use App\Support\Ledger\LedgerContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one reader of the journals for every report. It asks GlQuery for a ledger-confined query (every query carries
 * an explicit owner condition), adds the report's filters, and returns plain integers. Expensive aggregates are cached
 * against the journal fingerprint (see ReportCache).
 */
class Books
{
    public function __construct(private readonly GlQuery $query, private readonly CashBasis $cash, private readonly ReportCache $cache) {}

    /** @return Collection<int, GlAccount> every account of the ledger by id, inactive ones included */
    public function accounts(LedgerContext $context): Collection
    {
        return GlAccount::query()->forContext($context)->orderBy('code')->get()->keyBy('id');
    }

    /**
     * Debits and credits per GL account through the period, closing entries excluded.
     *
     * @return array<int, array{d: int, c: int}>
     */
    public function totals(ReportRequest $r, ?string $from, ?string $to, ?string $class = null, ?ReportBasis $basis = null): array
    {
        $basis ??= $r->basis;
        $rows = $this->plRows($r, $from, $to, ['gl_account_id'], $class === null ? ['income', 'expense'] : [$class], $basis);
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['gl_account_id']] = ['d' => $row['d'], 'c' => $row['c']];
        }

        return $out;
    }

    /**
     * Profit-and-loss movement grouped by any of gl_account_id, month, party_id, category_id, dimension.
     *
     * @param  list<string>  $groupBy
     * @param  list<string>  $classes  account classes to include (income and/or expense)
     * @return list<array<string, mixed>> each row has the group fields plus d (debits), c (credits) and net (debits minus credits)
     */
    public function plRows(ReportRequest $r, ?string $from, ?string $to, array $groupBy, array $classes = ['income', 'expense'], ?ReportBasis $basis = null): array
    {
        $basis ??= $r->basis;
        $key = ['from' => $from, 'to' => $to, 'g' => $groupBy, 'c' => $classes, 'b' => $basis->value];

        return $this->cache->remember($r, 'plRows', $key, function () use ($r, $from, $to, $groupBy, $classes, $basis) {
            $rows = $this->accrualRows($r->context, $from, $to, $groupBy, $classes, $basis === ReportBasis::Cash);

            if ($basis === ReportBasis::Cash && $to !== null) {
                $plIds = GlAccount::query()->forContext($r->context)->whereIn('class', $classes)->pluck('id')->flip();
                $merged = [];
                foreach ($rows as $row) {
                    $merged[$this->groupKey($row, $groupBy)] = $row;
                }
                foreach ($this->cash->events($r->context, $to) as $event) {
                    if (($from !== null && $event['date'] < $from) || ! $plIds->has($event['gl_account_id'])) {
                        continue;
                    }
                    $row = ['gl_account_id' => $event['gl_account_id'], 'month' => substr($event['date'], 0, 7), 'party_id' => $event['party_id'], 'category_id' => $event['category_id'], 'dimension' => $event['dimension']];
                    $k = $this->groupKey($row, $groupBy);
                    $merged[$k] ??= array_intersect_key($row, array_flip($groupBy)) + ['d' => 0, 'c' => 0, 'net' => 0];
                    $event['net'] > 0 ? $merged[$k]['d'] += $event['net'] : $merged[$k]['c'] -= $event['net'];
                    $merged[$k]['net'] += $event['net'];
                }
                $rows = array_values($merged);
            }

            return $rows;
        });
    }

    /**
     * Debits and credits per GL account through a date, over every class (a balance sheet needs the lot).
     *
     * @return array<int, array{d: int, c: int}>
     */
    public function balancesThrough(ReportRequest $r, string $to, bool $excludeClosing = false): array
    {
        return $this->cache->remember($r, 'balances', ['to' => $to, 'x' => $excludeClosing], function () use ($r, $to, $excludeClosing) {
            $out = [];
            foreach ($this->query->totals($r->context, null, $to, $excludeClosing) as $id => $t) {
                $out[(int) $id] = ['d' => (int) $t->debits, 'c' => (int) $t->credits];
            }

            return $out;
        });
    }

    /** Debits and credits per GL account between two dates, closing entries excluded; every class. @return array<int, array{d: int, c: int}> */
    public function movement(ReportRequest $r, ?string $from, string $to): array
    {
        return $this->cache->remember($r, 'movement', ['from' => $from, 'to' => $to], function () use ($r, $from, $to) {
            $q = $this->query->ownedLines($r->context)->where('l.entry_date', '<=', $to)->groupBy('l.gl_account_id')
                ->selectRaw('l.gl_account_id, COALESCE(SUM(l.debit_cents), 0) AS d, COALESCE(SUM(l.credit_cents), 0) AS c');
            if ($from !== null) {
                $q->where('l.entry_date', '>=', $from);
            }
            $this->withoutClosing($q, $r->context);

            $out = [];
            foreach ($q->get() as $row) {
                $out[(int) $row->gl_account_id] = ['d' => (int) $row->d, 'c' => (int) $row->c];
            }

            return $out;
        });
    }

    /**
     * Income and expense for a period straight from the journals, grouped by account class (closing entries left out). A second
     * route to the profit-and-loss figures, used by the reconciliation badges; deliberately not cached.
     *
     * @return array{income_cents: int, expense_cents: int, net_cents: int}
     */
    public function incomeExpense(LedgerContext $context, string $from, string $to): array
    {
        $q = $this->query->ownedLines($context)->join('gl_accounts as g', 'g.id', '=', 'l.gl_account_id')
            ->whereIn('g.class', ['income', 'expense'])->whereBetween('l.entry_date', [$from, $to])
            ->groupBy('g.class')->selectRaw('g.class, COALESCE(SUM(l.debit_cents), 0) AS d, COALESCE(SUM(l.credit_cents), 0) AS c');
        $this->withoutClosing($q, $context);
        $rows = $q->get()->keyBy('class');

        $income = (int) ($rows['income']->c ?? 0) - (int) ($rows['income']->d ?? 0);
        $expense = (int) ($rows['expense']->d ?? 0) - (int) ($rows['expense']->c ?? 0);

        return ['income_cents' => $income, 'expense_cents' => $expense, 'net_cents' => $income - $expense];
    }

    /**
     * What the year-end closing entries (and reversals of them) did to equity accounts in a range: credits minus debits.
     * A balance sheet needs it to show the current year's earnings separately from earlier years.
     */
    public function closingEffectOnEquity(ReportRequest $r, ?string $from, string $to): int
    {
        return $this->cache->remember($r, 'closingEquity', ['from' => $from, 'to' => $to], function () use ($r, $from, $to) {
            $q = $this->query->ownedLines($r->context)->join('gl_accounts as g', 'g.id', '=', 'l.gl_account_id')->where('g.class', 'equity')->where('l.entry_date', '<=', $to);
            if ($from !== null) {
                $q->where('l.entry_date', '>=', $from);
            }
            $this->onlyClosing($q, $r->context);

            return (int) $q->selectRaw('COALESCE(SUM(l.credit_cents), 0) - COALESCE(SUM(l.debit_cents), 0) AS net')->value('net');
        });
    }

    /** SQL for "the month of this date column" that behaves the same on every driver. */
    public static function monthSql(string $column): string
    {
        return match (DB::getDriverName()) {
            'sqlite' => "strftime('%Y-%m', {$column})",
            'mysql', 'mariadb' => "DATE_FORMAT({$column}, '%Y-%m')",
            default => "to_char({$column}, 'YYYY-MM')",
        };
    }

    /** @param  list<string>  $groupBy */
    private function accrualRows(LedgerContext $context, ?string $from, ?string $to, array $groupBy, array $classes, bool $withoutDocuments): array
    {
        $q = $this->query->ownedLines($context)->join('gl_accounts as g', 'g.id', '=', 'l.gl_account_id')->whereIn('g.class', $classes);

        if ($from !== null) {
            $q->where('l.entry_date', '>=', $from);
        }
        if ($to !== null) {
            $q->where('l.entry_date', '<=', $to);
        }

        $this->withoutClosing($q, $context);

        if ($withoutDocuments) {
            $this->withoutDocuments($q);
        }

        $select = [];
        $group = [];
        foreach ($groupBy as $field) {
            $expr = match ($field) {
                'gl_account_id' => 'l.gl_account_id',
                'month' => self::monthSql('l.entry_date'),
                'party_id' => 'l.party_id',
                'category_id' => 'l.category_id',
                'dimension' => 'l.dimension',
            };
            $select[] = "{$expr} AS {$field}";
            $group[] = $expr;
        }

        $q->selectRaw(implode(', ', $select).', COALESCE(SUM(l.debit_cents), 0) AS d, COALESCE(SUM(l.credit_cents), 0) AS c');
        foreach ($group as $expr) {
            $q->groupByRaw($expr);
        }

        return $q->get()->map(function ($row) use ($groupBy) {
            $out = [];
            foreach ($groupBy as $field) {
                $out[$field] = in_array($field, ['gl_account_id', 'party_id', 'category_id'], true) ? ($row->{$field} === null ? null : (int) $row->{$field}) : $row->{$field};
            }
            $out['d'] = (int) $row->d;
            $out['c'] = (int) $row->c;
            $out['net'] = $out['d'] - $out['c'];

            return $out;
        })->all();
    }

    /** @param  list<string>  $groupBy */
    private function groupKey(array $row, array $groupBy): string
    {
        return implode('|', array_map(fn ($f) => (string) ($row[$f] ?? ''), $groupBy));
    }

    /**
     * Ids of the year-end closing entries and of the entries that reverse them. There is at most a couple per financial
     * year, so the queries below filter on this short list instead of running a correlated subquery per journal line.
     *
     * @return list<int>
     */
    public function closingEntryIds(LedgerContext $context, ?string $fromDate = null): array
    {
        $closing = JournalEntry::query()->forContext($context)->where('source_type', 'period_close')->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($closing === []) {
            return [];
        }

        $reversals = JournalEntry::query()->forContext($context)->whereIn('reverses_entry_id', $closing)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $ids = array_merge($closing, $reversals);

        if ($fromDate !== null) {
            $ids = JournalEntry::query()->forContext($context)->whereIn('id', $ids)->where('entry_date', '>=', $fromDate)->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return $ids;
    }

    /** Leave out the year-end closing entries and the reversals of them. */
    public function withoutClosing(Builder $query, LedgerContext $context, ?string $fromDate = null): void
    {
        $ids = $this->closingEntryIds($context, $fromDate);
        if ($ids !== []) {
            $query->whereNotIn('l.journal_entry_id', $ids);
        }
    }

    /** Keep only the year-end closing entries and their reversals. */
    private function onlyClosing(Builder $query, LedgerContext $context): void
    {
        $query->whereIn('l.journal_entry_id', $this->closingEntryIds($context) ?: [0]);
    }

    /** Leave out the document entries the cash basis recognises on payment, and the reversals of them. */
    public function withoutDocuments(Builder $query): void
    {
        $types = $this->cash->documentTypes();
        $query->whereNotExists(function ($sub) use ($types) {
            $sub->selectRaw('1')->from('journal_entries as de')->whereColumn('de.id', 'l.journal_entry_id')
                ->where(function ($q) use ($types) {
                    $q->whereIn('de.source_type', $types)
                        ->orWhereIn('de.reverses_entry_id', fn ($s) => $s->select('dd.id')->from('journal_entries as dd')->whereIn('dd.source_type', $types));
                });
        });
    }
}
