<?php

namespace App\Support;

/**
 * Date handling for CSV import. PHP's strtotime() reads "03/04/2026" as March
 * 4th and "03-04-2026" as April 3rd, silently. Here the day/month order is an
 * explicit choice (yyyy-mm-dd, dd/mm/yyyy or mm/dd/yyyy), detected only when the
 * whole column agrees and otherwise handed back to the user.
 */
class ImportDates
{
    public const FORMATS = ['ymd', 'dmy', 'mdy'];

    public const LABELS = [
        'ymd' => 'yyyy-mm-dd (2026-04-03)',
        'dmy' => 'dd/mm/yyyy (03/04/2026 is 3 April)',
        'mdy' => 'mm/dd/yyyy (03/04/2026 is 4 March)',
    ];

    /** Returns Y-m-d, or null if $raw is not a real date in $format. */
    public static function parse(string $raw, string $format): ?string
    {
        $parts = self::split($raw);

        if ($parts === null) {
            return null;
        }

        [$a, $b, $c, $leadingYear] = $parts;

        if ($format === 'ymd') {
            if (! $leadingYear) {
                return null;
            }
            [$y, $m, $d] = [$a, $b, $c];
        } elseif ($format === 'dmy' || $format === 'mdy') {
            if ($leadingYear) {
                return null;
            }
            [$d, $m, $y] = $format === 'dmy' ? [$a, $b, $c] : [$b, $a, $c];
        } else {
            return null;
        }

        return checkdate($m, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
    }

    /**
     * @param  list<string>  $samples  every date cell in the column
     * @return array{format: ?string, ambiguous: bool}
     */
    public static function detect(array $samples): array
    {
        $evidence = [];
        $sawEitherWay = false;
        $sawEquals = false;
        $sawDayFirstOrMonthFirst = false;
        $parsed = 0;

        foreach ($samples as $sample) {
            $parts = self::split((string) $sample);

            if ($parts === null) {
                continue;
            }

            [$a, $b, , $leadingYear] = $parts;
            $parsed++;

            if ($leadingYear) {
                $evidence['ymd'] = true;
            } else {
                $sawDayFirstOrMonthFirst = true;
            }

            if ($leadingYear) {
                // handled above
            } elseif ($a > 12 && $b <= 12) {
                $evidence['dmy'] = true;
            } elseif ($b > 12 && $a <= 12) {
                $evidence['mdy'] = true;
            } elseif ($a <= 12 && $b <= 12) {
                $a === $b ? $sawEquals = true : $sawEitherWay = true;
            }
        }

        if ($parsed === 0) {
            return ['format' => null, 'ambiguous' => true];
        }

        // Evidence for two different readings is a conflict, never a guess; so is
        // a column that mixes yyyy-mm-dd with dd/mm/yyyy-style values.
        if (count($evidence) > 1 || (isset($evidence['ymd']) && $sawDayFirstOrMonthFirst)) {
            return ['format' => null, 'ambiguous' => true];
        }

        if (count($evidence) === 1) {
            return ['format' => array_key_first($evidence), 'ambiguous' => false];
        }

        if ($sawEitherWay) {
            return ['format' => null, 'ambiguous' => true];
        }

        // Only day == month dates ("05/05/2026"): both readings give the same day.
        return $sawEquals ? ['format' => 'dmy', 'ambiguous' => false] : ['format' => null, 'ambiguous' => true];
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: bool}|null three numbers, and whether the year came first
     */
    private static function split(string $raw): ?array
    {
        $value = trim($raw);

        // drop a trailing time ("2026-08-01 14:30", "2026-08-01T14:30:00Z")
        $value = preg_replace('/[T\s]+\d{1,2}:\d{2}(?::\d{2}(?:\.\d+)?)?\s*(?:Z|[+\-]\d{2}:?\d{2})?$/i', '', $value);

        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $value, $m) === 1) {
            return [(int) $m[1], (int) $m[2], (int) $m[3], true];
        }

        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $value, $m) === 1) {
            return [(int) $m[1], (int) $m[2], (int) $m[3], false];
        }

        return null;
    }
}
