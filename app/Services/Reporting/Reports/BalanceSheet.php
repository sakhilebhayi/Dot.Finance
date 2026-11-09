<?php

namespace App\Services\Reporting\Reports;

use App\Models\Accounting\GlAccount;
use App\Services\Reporting\ReportPeriod;
use App\Services\Reporting\ReportRequest;
use App\Services\Reporting\ReportResult;
use Carbon\CarbonImmutable;

/**
 * Balance sheet as at a date (the period's end), with an optional comparative date. Assets, liabilities, equity, and the
 * earnings that have not yet been closed to retained earnings, worked out from the journals so the sheet always balances.
 * Always accrual: a balance sheet is a position, not a flow, so the cash-basis toggle does not apply.
 */
class BalanceSheet extends Report
{
    public const NON_CURRENT_ASSETS = ['fixed_asset', 'accumulated_depreciation', 'investment'];

    public const NON_CURRENT_LIABILITIES = ['loan', 'deferred_tax'];

    public function key(): string
    {
        return 'balance-sheet';
    }

    public function title(): string
    {
        return 'Balance sheet';
    }

    public function description(): string
    {
        return 'What the business owns, owes and is worth on one date, with the current year\'s earnings worked out, and a comparison date.';
    }

    public function supportsComparison(): bool
    {
        return true;
    }

    public function isPointInTime(): bool
    {
        return true;
    }

    public function build(ReportRequest $r): ReportResult
    {
        $asAt = $r->period->to;
        $cmpAt = $r->comparison?->to;
        $accounts = $this->books->accounts($r->context);
        $fyStartMonth = (int) $r->settings->fiscal_year_start_month;

        $now = $this->position($r, $asAt, $accounts, $fyStartMonth);
        $then = $cmpAt === null ? null : $this->position($r, $cmpAt, $accounts, $fyStartMonth);

        $columns = [$this->col('label', 'Account', 'text'), $this->col('total', 'As at '.$this->day($asAt))];
        if ($then !== null) {
            $columns[] = $this->col('cmp', 'As at '.$this->day($cmpAt));
            $columns[] = $this->col('chg', 'Change');
        }

        $rows = [];
        $emit = function (string $type, string $label, int $now, ?int $then, array $drillNow, int $depth = 1) use (&$rows, $cmpAt, $asAt) {
            $cells = ['total' => $now];
            $drill = ['total' => $drillNow];
            if ($then !== null) {
                $cells += ['cmp' => $then, 'chg' => $now - $then];
                $drill['cmp'] = array_replace($drillNow, ['to' => $cmpAt]) + [];
            }
            $rows[] = $this->row($type, $label, $cells, $drill, $depth);
        };

        $val = fn (array $position, string $key, int $id) => $position['by'][$key][$id] ?? 0;

        // Assets
        $rows[] = $this->section('Assets');
        foreach (['Current assets' => false, 'Non-current assets' => true] as $label => $nonCurrent) {
            $set = $accounts->filter(fn (GlAccount $a) => $a->class === 'asset' && in_array($a->subtype, self::NON_CURRENT_ASSETS, true) === $nonCurrent);
            $subNow = 0;
            $subThen = 0;
            $lines = [];
            foreach ($set as $a) {
                $n = $val($now, 'asset', $a->id);
                $t = $then === null ? null : $val($then, 'asset', $a->id);
                if ($n === 0 && ($t ?? 0) === 0) {
                    continue;
                }
                $subNow += $n;
                $subThen += $t ?? 0;
                $lines[] = [$a, $n, $t];
            }
            if ($lines === []) {
                continue;
            }
            $rows[] = $this->row('line', $label, [], [], 1);
            foreach ($lines as [$a, $n, $t]) {
                $emit('line', $this->accountLabel($a), $n, $t, $this->drill('', $asAt, ['account' => $a->id]), 2);
            }
            $emit('subtotal', 'Total '.strtolower($label), $subNow, $then === null ? null : $subThen, $this->drill('', $asAt, ['class' => 'asset', 'bs_group' => $nonCurrent ? 'noncurrent' : 'current']), 1);
        }
        $emit('total', 'Total assets', $now['assets'], $then['assets'] ?? null, $this->drill('', $asAt, ['class' => 'asset']), 0);

        // Liabilities
        $rows[] = $this->section('Liabilities');
        foreach (['Current liabilities' => false, 'Non-current liabilities' => true] as $label => $nonCurrent) {
            $set = $accounts->filter(fn (GlAccount $a) => $a->class === 'liability' && in_array($a->subtype, self::NON_CURRENT_LIABILITIES, true) === $nonCurrent);
            $subNow = 0;
            $subThen = 0;
            $lines = [];
            foreach ($set as $a) {
                $n = $val($now, 'liability', $a->id);
                $t = $then === null ? null : $val($then, 'liability', $a->id);
                if ($n === 0 && ($t ?? 0) === 0) {
                    continue;
                }
                $subNow += $n;
                $subThen += $t ?? 0;
                $lines[] = [$a, $n, $t];
            }
            if ($lines === []) {
                continue;
            }
            $rows[] = $this->row('line', $label, [], [], 1);
            foreach ($lines as [$a, $n, $t]) {
                $emit('line', $this->accountLabel($a), $n, $t, $this->drill('', $asAt, ['account' => $a->id]), 2);
            }
            $emit('subtotal', 'Total '.strtolower($label), $subNow, $then === null ? null : $subThen, $this->drill('', $asAt, ['class' => 'liability', 'bs_group' => $nonCurrent ? 'noncurrent' : 'current']), 1);
        }
        $emit('total', 'Total liabilities', $now['liabilities'], $then['liabilities'] ?? null, $this->drill('', $asAt, ['class' => 'liability']), 0);

        // Equity
        $rows[] = $this->section('Equity');
        foreach ($accounts->filter(fn (GlAccount $a) => $a->class === 'equity') as $a) {
            $n = $val($now, 'equity', $a->id);
            $t = $then === null ? null : $val($then, 'equity', $a->id);
            if ($n === 0 && ($t ?? 0) === 0) {
                continue;
            }
            $extra = ['account' => $a->id];
            if ($a->system_key === 'retained_earnings') {
                $extra['noclose_from'] = $now['fy_start'];
            }
            $emit('line', $this->accountLabel($a), $n, $t, $this->drill('', $asAt, $extra));
        }
        if ($now['earlier'] !== 0 || ($then['earlier'] ?? 0) !== 0) {
            $emit('line', 'Earnings of earlier years, not yet closed to retained earnings', $now['earlier'], $then['earlier'] ?? null, $this->drill('', CarbonImmutable::parse($now['fy_start'])->subDay()->toDateString(), ['class' => 'pl']));
        }
        $emit('line', 'Current year earnings (calculated)', $now['current_year'], $then['current_year'] ?? null, $this->drill($now['fy_start'], $asAt, ['class' => 'pl', 'noclose' => 1]));
        $emit('total', 'Total equity', $now['equity'], $then['equity'] ?? null, $this->drill('', $asAt, ['class' => 'equity_with_earnings']), 0);

        $emit('total', 'Total liabilities and equity', $now['liabilities'] + $now['equity'], $then === null ? null : $then['liabilities'] + $then['equity'], $this->drill('', $asAt, ['class' => 'le']), 0);

        $checks = $this->recon->balanceSheet($r, $asAt, $now['assets'], $now['liabilities'], $now['equity']);
        $checks[] = $this->recon->bank($r, $asAt);

        $empty = $now['assets'] === 0 && $now['liabilities'] === 0 && $now['equity'] === 0
            ? 'There are no balances yet. Record a transaction or an opening balance and the position builds itself.' : null;

        return new ReportResult($this->key(), $this->title(), $this->header($r, [
            'Accrual basis: a balance sheet is a position on one date, so the cash-basis option does not change it.',
            'Current year earnings are the profit or loss of the financial year that contains the date, calculated from the journals; they move into retained earnings when the year is closed.',
        ], 'As at '.$this->day($asAt).($cmpAt ? ' (compared with '.$this->day($cmpAt).')' : '')), $columns, $rows, $checks, [
            'Foreign currency balances are shown in the base currency at the rate posted; the ledger does not revalue them (unrealised exchange differences are not calculated yet).',
        ], $empty);
    }

    /**
     * @return array{by: array<string, array<int, int>>, assets: int, liabilities: int, equity: int, earlier: int, current_year: int, fy_start: string}
     */
    private function position(ReportRequest $r, string $asAt, $accounts, int $fyStartMonth): array
    {
        $fyStart = ReportPeriod::fiscalYearStart(CarbonImmutable::parse($asAt), $fyStartMonth)->toDateString();
        $balances = $this->books->balancesThrough($r, $asAt);

        $out = ['by' => ['asset' => [], 'liability' => [], 'equity' => []], 'assets' => 0, 'liabilities' => 0, 'equity' => 0, 'earlier' => 0, 'current_year' => 0, 'fy_start' => $fyStart];

        foreach ($accounts as $a) {
            $t = $balances[$a->id] ?? ['d' => 0, 'c' => 0];
            $net = $t['d'] - $t['c'];
            if ($a->class === 'asset') {
                $out['by']['asset'][$a->id] = $net;
                $out['assets'] += $net;
            } elseif ($a->class === 'liability') {
                $out['by']['liability'][$a->id] = -$net;
                $out['liabilities'] += -$net;
            } elseif ($a->class === 'equity') {
                $out['by']['equity'][$a->id] = -$net;
                $out['equity'] += -$net;
            }
        }

        // The closing entry of this financial year (if it has been closed by the date) moved the year's result into retained
        // earnings; show that part as the year's earnings instead, so the year reads as itself either way.
        $closedThisYear = $this->books->closingEffectOnEquity($r, $fyStart, $asAt);
        if ($closedThisYear !== 0) {
            foreach ($accounts as $a) {
                if ($a->class === 'equity' && $a->system_key === 'retained_earnings') {
                    $out['by']['equity'][$a->id] -= $closedThisYear;
                    $out['equity'] -= $closedThisYear;
                }
            }
        }

        // Earnings of earlier years that were never closed: the income and expense accounts still carry them (closing entries included).
        $before = CarbonImmutable::parse($fyStart)->subDay()->toDateString();
        $priorBalances = $this->books->balancesThrough($r, $before);
        $earlier = 0;
        foreach ($accounts as $a) {
            if ($a->class === 'income' || $a->class === 'expense') {
                $t = $priorBalances[$a->id] ?? ['d' => 0, 'c' => 0];
                $earlier += $t['c'] - $t['d'];
            }
        }

        $year = $this->books->totals($r, $fyStart, $asAt, null, \App\Enums\ReportBasis::Accrual);
        $current = 0;
        foreach ($accounts as $a) {
            if (isset($year[$a->id])) {
                $current += $year[$a->id]['c'] - $year[$a->id]['d'];
            }
        }

        $out['earlier'] = $earlier;
        $out['current_year'] = $current;
        $out['equity'] += $earlier + $current;

        return $out;
    }

    private function day(string $date): string
    {
        return CarbonImmutable::parse($date)->format('j M Y');
    }
}
