<?php

namespace App\Services\Reporting;

use App\Support\Money;
use Carbon\CarbonImmutable;

/** How a report cell that is not money is written: percentages (from basis points), dates and counts. */
final class ReportFormat
{
    /** 1234 basis points -> "12.3%", -50 -> "-0.5%". */
    public static function pct(int $bp): string
    {
        $tenths = Money::divideRound(abs($bp), 10);

        return ($bp < 0 && $tenths > 0 ? '-' : '').intdiv($tenths, 10).'.'.($tenths % 10).'%';
    }

    public static function date(?string $date): string
    {
        return $date === null || $date === '' ? '' : CarbonImmutable::parse($date)->format('j M Y');
    }

    /** The cell as plain text for a non-money column. */
    public static function text(string $type, mixed $value): string
    {
        return match ($type) {
            'pct' => self::pct((int) $value),
            'date' => self::date((string) $value),
            'count' => number_format((int) $value),
            default => (string) $value,
        };
    }
}
