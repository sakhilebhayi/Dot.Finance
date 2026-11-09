<?php

namespace App\Support;

/**
 * What a budget bar and its caption say, worked out with integers only (R-44 polish, D-1702). A budget that has been spent past
 * its limit says by how much and by what percentage, instead of reading "100% used" at 318%: the fill is scaled to the larger of
 * the spend and the limit so the overrun is visible, with the limit marked on the bar.
 */
final class BudgetProgress
{
    /**
     * @return array{spent_cents: int, limit_cents: int, used_pct: int, over: bool, at_limit: bool, over_cents: int, over_pct: int, left_cents: int, limit_mark_pct: int, within_pct: int, overrun_pct: int, caption: string, short: string}
     */
    public static function for(int $spentCents, int $limitCents, ?string $currency = null): array
    {
        $spent = max(0, $spentCents);
        $limit = max(0, $limitCents);
        $usedPct = $limit > 0 ? intdiv($spent * 100, $limit) : 0;
        $over = $limit > 0 && $spent > $limit;
        $atLimit = $limit > 0 && $spent === $limit;
        $overCents = $over ? $spent - $limit : 0;
        $overPct = $over ? intdiv($overCents * 100, $limit) : 0;
        $left = $limit > 0 && ! $over ? $limit - $spent : 0;

        // The bar's full width stands for the larger of spend and limit, so the limit mark moves left as the overrun grows.
        $scale = max($spent, $limit);
        $mark = $scale > 0 ? intdiv($limit * 100, $scale) : 100;
        $within = $scale > 0 ? intdiv(min($spent, $limit) * 100, $scale) : 0;
        $overrun = $over && $scale > 0 ? 100 - $mark : 0;

        if ($over) {
            $caption = Money::format($overCents, $currency).' over the limit ('.$overPct.'% more than planned)';
            $short = Money::format($overCents, $currency).' over';
        } elseif ($atLimit) {
            $caption = 'Exactly at the limit, nothing left';
            $short = 'At the limit';
        } elseif ($limit > 0) {
            $caption = $usedPct.'% used, '.Money::format($left, $currency).' left';
            $short = $usedPct.'% used';
        } else {
            $caption = 'No limit set';
            $short = 'No limit';
        }

        return [
            'spent_cents' => $spent, 'limit_cents' => $limit, 'used_pct' => $usedPct, 'over' => $over, 'at_limit' => $atLimit,
            'over_cents' => $overCents, 'over_pct' => $overPct, 'left_cents' => $left, 'limit_mark_pct' => $mark, 'within_pct' => $within,
            'overrun_pct' => $overrun, 'caption' => $caption, 'short' => $short,
        ];
    }
}
