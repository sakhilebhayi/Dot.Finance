<?php

namespace App\Services\Reporting;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalEntry;
use App\Services\Accounting\GlQuery;
use App\Services\Reporting\Reports\BalanceSheet;
use App\Support\Ledger\LedgerContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The journal lines behind a report figure. A report cell is a set of filters (account, class, dates, party...); this
 * returns exactly the lines that make it up, with a total in the same sign the report shows, so the figure and its
 * drill-down can be compared to the cent. Every line carries a link to its journal entry and, when there is one, its
 * source document. On the cash basis the lines of invoices and bills are shown with the share recognised in the period.
 */
class DrillDown
{
    public const CLASSES = ['asset', 'liability', 'equity', 'income', 'expense', 'pl', 'income_cos', 'equity_with_earnings', 'le', 'income_expense', 'vat', 'cash'];

    private const CREDIT = ['income', 'liability', 'equity', 'pl', 'income_cos', 'equity_with_earnings', 'le', 'vat'];

    public function __construct(private readonly GlQuery $query, private readonly Books $books, private readonly CashBasis $cash) {}

    /**
     * @param  array<string, mixed>  $f  validated filters
     * @return array{lines: LengthAwarePaginator, total_cents: int, debit_cents: int, credit_cents: int, count: int, mode: string, label: string, notes: list<string>}
     */
    public function lines(LedgerContext $context, array $f, int $page = 1, int $perPage = 50): array
    {
        $account = isset($f['account']) ? GlAccount::query()->forContext($context)->find((int) $f['account']) : null;
        $mode = $this->signMode($f, $account);
        $cashBasis = ($f['basis'] ?? null) === 'cash' && $this->isProfitAndLoss($f, $account);
        $notes = [];

        $base = $this->base($context, $f, $account, $cashBasis);
        $naturalSql = match ($mode) {
            'credit' => '(l.credit_cents - l.debit_cents)',
            'line' => "(CASE WHEN g.class IN ('income','liability','equity') THEN l.credit_cents - l.debit_cents ELSE l.debit_cents - l.credit_cents END)",
            default => '(l.debit_cents - l.credit_cents)',
        };

        $sums = (clone $base)->selectRaw("COALESCE(SUM(l.debit_cents),0) AS d, COALESCE(SUM(l.credit_cents),0) AS c, COALESCE(SUM({$naturalSql}),0) AS n, COUNT(*) AS k")->first();

        $events = [];
        if ($cashBasis && ! empty($f['to'])) {
            $events = $this->recognitionEvents($context, $f, $account);
            if ($events !== []) {
                $notes[] = 'Cash basis: invoices and bills appear with the share recognised in the period (see "Recognised"); their full posted lines are not counted.';
            }
        }

        $eventNet = array_sum(array_map(fn ($e) => $this->signed($e['net'], $mode, $e['class']), $events));
        $debit = (int) $sums->d + array_sum(array_map(fn ($e) => max(0, $e['net']), $events));
        $credit = (int) $sums->c + array_sum(array_map(fn ($e) => max(0, -$e['net']), $events));
        $count = (int) $sums->k + count($events);

        if ($events === []) {
            $rows = (clone $base)->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->orderBy('l.entry_date')->orderBy('e.entry_number')->orderBy('l.line_no')
                ->forPage($page, $perPage)
                ->get(['l.id', 'l.entry_date', 'l.debit_cents', 'l.credit_cents', 'l.description', 'l.gl_account_id', 'l.party_id', 'l.dimension', 'g.code as account_code', 'g.name as account_name', 'e.id as entry_id', 'e.entry_number', 'e.memo', 'e.source_type', 'e.source_id', 'e.status']);
            $rows = $rows->map(fn ($r) => $this->present($r, null));
        } else {
            $all = (clone $base)->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->limit(20000)
                ->get(['l.id', 'l.entry_date', 'l.debit_cents', 'l.credit_cents', 'l.description', 'l.gl_account_id', 'l.party_id', 'l.dimension', 'g.code as account_code', 'g.name as account_name', 'e.id as entry_id', 'e.entry_number', 'e.memo', 'e.source_type', 'e.source_id', 'e.status'])
                ->map(fn ($r) => $this->present($r, null));
            $merged = $all->concat($this->presentEvents($context, $events))->sortBy(fn ($r) => [$r['date'], $r['entry_number'], $r['id']])->values();
            $rows = $merged->forPage($page, $perPage)->values();
        }

        $label = $account ? $account->code.' '.$account->name : ($f['class'] ?? 'journal lines');

        return [
            'lines' => new LengthAwarePaginator($rows, $count, $perPage, $page, ['path' => request()->url(), 'query' => request()->query()]),
            'total_cents' => (int) $sums->n + $eventNet,
            'debit_cents' => $debit,
            'credit_cents' => $credit,
            'count' => $count,
            'mode' => $mode,
            'label' => $label,
            'notes' => $notes,
        ];
    }

    /** Whether the filters describe profit-and-loss lines (so the year-end closing entries are left out and the cash basis can apply). */
    private function isProfitAndLoss(array $f, ?GlAccount $account): bool
    {
        if ($account !== null) {
            return in_array($account->class, ['income', 'expense'], true);
        }

        return in_array($f['class'] ?? null, ['income', 'expense', 'pl', 'income_cos', 'income_expense'], true);
    }

    private function signMode(array $f, ?GlAccount $account): string
    {
        if (in_array($f['sign'] ?? null, ['credit', 'debit'], true)) {
            return $f['sign'];
        }

        if ($account !== null) {
            return in_array($account->class, ['income', 'liability', 'equity'], true) ? 'credit' : 'debit';
        }

        $class = $f['class'] ?? null;

        return match (true) {
            $class === 'income_expense' => 'line',
            in_array($class, self::CREDIT, true) => 'credit',
            default => 'debit',
        };
    }

    private function base(LedgerContext $context, array $f, ?GlAccount $account, bool $cashBasis): Builder
    {
        $q = $this->query->ownedLines($context)->join('gl_accounts as g', 'g.id', '=', 'l.gl_account_id');

        if ($account !== null) {
            $q->where('l.gl_account_id', $account->id);
        }
        if (! empty($f['from'])) {
            $q->where('l.entry_date', '>=', $f['from']);
        }
        if (! empty($f['to'])) {
            $q->where('l.entry_date', '<=', $f['to']);
        }

        match ($f['class'] ?? null) {
            'asset', 'liability', 'equity', 'income', 'expense' => $q->where('g.class', $f['class']),
            'pl', 'income_expense' => $q->whereIn('g.class', ['income', 'expense']),
            'income_cos' => $q->where(fn ($w) => $w->where('g.class', 'income')->orWhere(fn ($x) => $x->where('g.class', 'expense')->where('g.subtype', 'cost_of_sales'))),
            'equity_with_earnings' => $q->whereIn('g.class', ['equity', 'income', 'expense']),
            'le' => $q->whereIn('g.class', ['liability', 'equity', 'income', 'expense']),
            'vat' => $q->whereIn('g.system_key', ['vat_output', 'vat_input', 'vat_control']),
            'cash' => $q->where('g.class', 'asset')->whereIn('g.subtype', ['bank', 'cash']),
            default => null,
        };

        if (! empty($f['subtype'])) {
            $q->where('g.subtype', $f['subtype']);
        }
        if (! empty($f['not_subtype'])) {
            $q->where(fn ($w) => $w->whereNull('g.subtype')->orWhere('g.subtype', '!=', $f['not_subtype']));
        }
        if (! empty($f['bs_group']) && ! empty($f['class'])) {
            $list = $f['class'] === 'asset' ? BalanceSheet::NON_CURRENT_ASSETS : BalanceSheet::NON_CURRENT_LIABILITIES;
            $f['bs_group'] === 'noncurrent'
                ? $q->whereIn('g.subtype', $list)
                : $q->where(fn ($w) => $w->whereNull('g.subtype')->orWhereNotIn('g.subtype', $list));
        }

        foreach (['party' => 'l.party_id', 'category' => 'l.category_id', 'dimension' => 'l.dimension'] as $key => $column) {
            if (isset($f[$key]) && $f[$key] !== '') {
                $f[$key] === 'none' ? $q->whereNull($column) : $q->where($column, $f[$key]);
            }
        }
        if (! empty($f['tax_code'])) {
            $q->where('l.tax_code_id', (int) $f['tax_code']);
        }

        if (! empty($f['cash_entries'])) {
            $cashIds = GlAccount::query()->forContext($context)->where('class', 'asset')->whereIn('subtype', ['bank', 'cash'])->pluck('id')->all();
            $q->whereNotIn('l.gl_account_id', $cashIds ?: [0])
                ->whereExists(fn ($sub) => $sub->selectRaw('1')->from('journal_lines as cl')->whereColumn('cl.journal_entry_id', 'l.journal_entry_id')->whereIn('cl.gl_account_id', $cashIds ?: [0]));
        }

        $profitAndLoss = $this->isProfitAndLoss($f, $account) && ! empty($f['basis']);
        if (! empty($f['noclose']) || $profitAndLoss) {
            $this->books->withoutClosing($q);
        } elseif (! empty($f['noclose_from'])) {
            $this->books->withoutClosing($q, (string) $f['noclose_from']);
        }

        if ($cashBasis) {
            $this->books->withoutDocuments($q);
        }

        return $q;
    }

    /** @return list<array<string, mixed>> */
    private function recognitionEvents(LedgerContext $context, array $f, ?GlAccount $account): array
    {
        $accounts = GlAccount::query()->forContext($context)->get()->keyBy('id');
        $out = [];
        foreach ($this->cash->events($context, (string) $f['to']) as $e) {
            $a = $accounts->get($e['gl_account_id']);
            if ($a === null || ! in_array($a->class, ['income', 'expense'], true)) {
                continue;
            }
            if (! empty($f['from']) && $e['date'] < $f['from']) {
                continue;
            }
            if ($account !== null && $a->id !== $account->id) {
                continue;
            }
            $class = $f['class'] ?? null;
            $ok = match ($class) {
                'income', 'expense' => $a->class === $class,
                'income_cos' => $a->class === 'income' || $a->subtype === 'cost_of_sales',
                null, 'pl', 'income_expense' => true,
                default => false,
            };
            if (! $ok || (! empty($f['subtype']) && $a->subtype !== $f['subtype']) || (! empty($f['not_subtype']) && $a->subtype === $f['not_subtype'])) {
                continue;
            }
            foreach (['party' => 'party_id', 'category' => 'category_id', 'dimension' => 'dimension'] as $key => $field) {
                if (isset($f[$key]) && $f[$key] !== '' && ($f[$key] === 'none' ? $e[$field] !== null : (string) $e[$field] !== (string) $f[$key])) {
                    continue 2;
                }
            }
            $out[] = $e + ['class' => $a->class, 'code' => $a->code, 'name' => $a->name];
        }

        return $out;
    }

    private function signed(int $net, string $mode, string $class): int
    {
        return match ($mode) {
            'credit' => -$net,
            'line' => in_array($class, ['income', 'liability', 'equity'], true) ? -$net : $net,
            default => $net,
        };
    }

    /** @return array<string, mixed> */
    private function present(object $r, ?int $recognised): array
    {
        $entry = (new JournalEntry)->forceFill(['source_type' => $r->source_type, 'source_id' => $r->source_id]);
        $source = $this->query->source($entry);

        return [
            'id' => (int) $r->id, 'date' => substr((string) $r->entry_date, 0, 10), 'entry_id' => (int) $r->entry_id, 'entry_number' => (int) $r->entry_number,
            'entry' => 'JE-'.str_pad((string) $r->entry_number, 6, '0', STR_PAD_LEFT), 'memo' => (string) $r->memo, 'description' => (string) ($r->description ?? ''),
            'account' => $r->account_code.' '.$r->account_name, 'account_id' => (int) $r->gl_account_id,
            'debit_cents' => (int) $r->debit_cents, 'credit_cents' => (int) $r->credit_cents, 'recognised_cents' => $recognised,
            'source_label' => $source['label'], 'source_route' => $source['route'], 'source_parameter' => $source['parameter'],
            'reversed' => ($r->status ?? 'posted') === 'reversed',
        ];
    }

    /** @param  list<array<string, mixed>>  $events @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function presentEvents(LedgerContext $context, array $events)
    {
        $entries = JournalEntry::query()->forContext($context)->whereIn('id', array_unique(array_column($events, 'entry_id')))->get()->keyBy('id');

        return collect($events)->map(function ($e) use ($entries) {
            $entry = $entries->get($e['entry_id']);
            $source = $entry ? $this->query->source($entry) : ['label' => 'Document', 'route' => null, 'parameter' => null];

            return [
                'id' => (int) $e['line_id'], 'date' => $e['date'], 'entry_id' => (int) $e['entry_id'], 'entry_number' => (int) ($entry->entry_number ?? 0),
                'entry' => 'JE-'.str_pad((string) ($entry->entry_number ?? 0), 6, '0', STR_PAD_LEFT), 'memo' => (string) ($entry->memo ?? ''), 'description' => 'Recognised on payment',
                'account' => $e['code'].' '.$e['name'], 'account_id' => (int) $e['gl_account_id'],
                'debit_cents' => max(0, $e['net']), 'credit_cents' => max(0, -$e['net']), 'recognised_cents' => $e['net'],
                'source_label' => $source['label'], 'source_route' => $source['route'], 'source_parameter' => $source['parameter'], 'reversed' => false,
            ];
        });
    }
}
