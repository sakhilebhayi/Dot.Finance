<?php

namespace App\Services\Planning;

use App\Services\Reporting\Books;
use App\Services\Reporting\ReportRequest;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Revenue and expense forecasts from the ledger's own monthly history, by methods anyone can follow by hand:
 *
 *   trailing_average  the average of the last three months, held flat
 *   linear_trend      a straight line through the last twelve months (least squares, in integers)
 *   seasonal_naive    the same calendar month last year (only with at least 24 months of history)
 *
 * Each method is back-tested on the last three months it could have predicted (one step ahead, using only the months
 * before): the absolute error in money and as a share of what actually happened is shown, and the method with the smallest
 * total error is the default. The same history always gives the same forecast. A forecast is an extrapolation of the past,
 * not a promise; with less than six months of history the engine refuses instead of guessing.
 */
class Forecaster
{
    public const METHODS = [
        'trailing_average' => 'Trailing 3-month average',
        'linear_trend' => 'Linear trend over the last 12 months',
        'seasonal_naive' => 'Same month last year',
    ];

    public function __construct(private readonly Books $books) {}

    /**
     * Monthly history of one class as natural-sign cents, oldest first, for the complete months before $today, with months that
     * had no postings between the first and last month as zero.
     *
     * @return array<string, int> 'Y-m' => cents
     */
    public function history(ReportRequest $request, string $class, CarbonImmutable $today): array
    {
        $lastMonth = $today->startOfMonth()->subMonthNoOverflow();
        $rows = $this->books->plRows($request, null, $lastMonth->endOfMonth()->toDateString(), ['month'], [$class]);
        $sign = $class === 'income' ? -1 : 1;

        $series = [];
        foreach ($rows as $row) {
            $series[$row['month']] = ($series[$row['month']] ?? 0) + $sign * $row['net'];
        }
        if ($series === []) {
            return [];
        }

        ksort($series);
        $first = CarbonImmutable::parse(array_key_first($series).'-01');
        $filled = [];
        for ($c = $first; $c->lte($lastMonth); $c = $c->addMonthNoOverflow()) {
            $filled[$c->format('Y-m')] = $series[$c->format('Y-m')] ?? 0;
        }

        return $filled;
    }

    /**
     * @param  array<string, int>  $history  from history()
     * @return array<string, mixed>
     */
    public function forecast(array $history, int $horizonMonths = 6, ?string $method = null): array
    {
        $min = (int) config('reporting.forecast.min_history_months', 6);
        $months = array_keys($history);
        $values = array_values($history);
        $n = count($values);

        if ($n < $min) {
            return ['ok' => false, 'reason' => "A forecast needs at least {$min} months of history; the books have {$n}. Nothing is forecast until there is enough to learn from."];
        }

        if (max(array_map('abs', $values)) > 100_000_000_000) {
            return ['ok' => false, 'reason' => 'The monthly amounts are too large to forecast safely in whole cents.'];
        }

        $back = (int) config('reporting.forecast.backtest_periods', 3);
        $seasonalOk = $n >= (int) config('reporting.forecast.seasonal_min_months', 24);
        $eligible = array_keys(array_filter(self::METHODS, fn ($label, $key) => $key !== 'seasonal_naive' || $seasonalOk, ARRAY_FILTER_USE_BOTH));

        $tests = [];
        foreach ($eligible as $m) {
            $abs = 0;
            $actualSum = 0;
            $points = [];
            for ($t = $n - $back; $t < $n; $t++) {
                if ($t < 3) {
                    continue;
                }
                $predicted = $this->predict($m, array_slice($values, 0, $t), 1);
                $err = $predicted - $values[$t];
                $abs += abs($err);
                $actualSum += abs($values[$t]);
                $points[] = ['month' => $months[$t], 'predicted_cents' => $predicted, 'actual_cents' => $values[$t], 'error_cents' => $err];
            }
            $count = count($points);
            $tests[$m] = [
                'method' => $m, 'label' => self::METHODS[$m], 'points' => $points,
                'mae_cents' => $count === 0 ? 0 : Money::divideRound($abs, $count), 'abs_error_cents' => $abs,
                'error_bp' => $actualSum === 0 ? null : Money::divideRound($abs * 10000, $actualSum),
            ];
        }

        $best = array_reduce($eligible, fn ($carry, $m) => $carry === null || $tests[$m]['abs_error_cents'] < $tests[$carry]['abs_error_cents'] ? $m : $carry);
        $chosen = $method !== null && in_array($method, $eligible, true) ? $method : $best;

        $next = CarbonImmutable::parse(end($months).'-01')->addMonthNoOverflow();
        $out = [];
        for ($h = 1; $h <= $horizonMonths; $h++) {
            $out[] = ['month' => $next->addMonthsNoOverflow($h - 1)->format('Y-m'), 'cents' => $this->predict($chosen, $values, $h)];
        }

        return [
            'ok' => true, 'method' => $chosen, 'label' => self::METHODS[$chosen], 'best_method' => $best, 'history_months' => $n, 'seasonal_available' => $seasonalOk,
            'forecast' => $out, 'backtest' => array_values($tests), 'band_cents' => $tests[$chosen]['mae_cents'],
            'assumptions' => $this->assumptionText($chosen, $n),
        ];
    }

    /** Predict $h months beyond the end of $values with one method. Pure and deterministic. @param  list<int>  $values */
    public function predict(string $method, array $values, int $h): int
    {
        $n = count($values);

        return max(0, match ($method) {
            'trailing_average' => $this->trailing($values),
            'linear_trend' => $this->trend(array_slice($values, -12), $h),
            'seasonal_naive' => $n >= 12 ? $values[$n - 12 + (($h - 1) % 12)] : $this->trailing($values),
        });
    }

    /** @param  list<int>  $values */
    private function trailing(array $values): int
    {
        $tail = array_slice($values, -3);

        return $tail === [] ? 0 : Money::divideRound(array_sum($tail), count($tail));
    }

    /**
     * Least-squares line through (0, y0)...(n-1, y_{n-1}), evaluated at x = n - 1 + h, in integer arithmetic:
     * slope = N/D with N = n*Sxy - Sx*Sy and D = n*Sxx - Sx^2, value = (Sy*D - N*Sx + n*N*x) / (n*D).
     *
     * @param  list<int>  $y
     */
    private function trend(array $y, int $h): int
    {
        $n = count($y);
        if ($n < 2) {
            return $n === 1 ? $y[0] : 0;
        }

        $sx = $sy = $sxy = $sxx = 0;
        foreach ($y as $x => $v) {
            $sx += $x;
            $sy += $v;
            $sxy += $x * $v;
            $sxx += $x * $x;
        }

        $num = $n * $sxy - $sx * $sy;
        $den = $n * $sxx - $sx * $sx;
        $x = $n - 1 + $h;

        // n * D is at most 12 * 572 here, and the numerator is a sum of amounts: both stay far inside an int.
        return Money::divideRound($sy * $den - $num * $sx + $n * $num * $x, $n * $den);
    }

    private function assumptionText(string $method, int $history): array
    {
        $common = ["Based on {$history} complete months of history in the ledger (accrual basis, year-end closing entries left out).", 'It assumes the coming months look like the recent past; new customers, price changes, one-off items and seasons it has not seen are not in it.'];

        return array_merge(match ($method) {
            'trailing_average' => ['Method: the average of the last three months, held flat for every forecast month.'],
            'linear_trend' => ['Method: a straight line (least squares) through the last twelve months, extended forward.'],
            'seasonal_naive' => ['Method: each forecast month repeats the same calendar month of the last twelve.'],
        }, $common);
    }
}
