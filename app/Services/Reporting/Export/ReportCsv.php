<?php

namespace App\Services\Reporting\Export;

use App\Services\Reporting\ReportResult;
use App\Support\Money;

/**
 * Writes a ReportResult as CSV. Figures are plain decimals (no thousands separators, no currency symbol, a minus sign for
 * a negative) so a spreadsheet reads them as numbers; text that a spreadsheet could take for a formula is neutralised.
 */
final class ReportCsv
{
    /** Stream the report to an open handle. @param  resource  $out */
    public static function write(ReportResult $report, $out): int
    {
        $lines = 0;
        $put = function (array $row) use ($out, &$lines) {
            fputcsv($out, array_map([self::class, 'safe'], $row), ',', '"', '');
            $lines++;
        };

        $h = $report->header;
        $put([$report->title]);
        $put(['Ledger', $h['ledger'] ?? '']);
        $put(['Period', $h['period'] ?? '']);
        if (! empty($h['comparison'])) {
            $put(['Compared with', $h['comparison']]);
        }
        $put(['Currency', $h['currency'] ?? '']);
        $put(['Basis', $h['basis'] ?? '']);
        $put(['Generated at', $h['generated_at'] ?? '']);
        foreach ($h['rule_versions'] ?? [] as $name => $version) {
            $put([$name, $version]);
        }
        foreach ($h['assumptions'] ?? [] as $assumption) {
            $put(['Assumption', $assumption]);
        }
        $put([]);

        $put(array_map(fn ($c) => $c['label'], $report->columns));
        foreach ($report->rows as $row) {
            if ($row['type'] === 'section') {
                $put([$row['label']]);

                continue;
            }
            $cells = [];
            foreach ($report->columns as $i => $col) {
                $cells[] = $i === 0 && $col['key'] === 'label' ? str_repeat('  ', max(0, ($row['depth'] ?? 1) - 1)).$row['label'] : self::cell($col['type'], $row['cells'][$col['key']] ?? null);
            }
            $put($cells);
        }

        if ($report->checks !== []) {
            $put([]);
            foreach ($report->checks as $c) {
                $put(['Check', $c['label'], $c['ok'] ? 'OK' : 'DOES NOT AGREE', Money::toDecimalString((int) $c['difference_cents']), $c['detail']]);
            }
        }
        foreach ($report->notes as $note) {
            $put(['Note', $note]);
        }

        return $lines;
    }

    public static function render(ReportResult $report): string
    {
        $handle = fopen('php://temp', 'w+');
        self::write($report, $handle);
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /** The cell as the export shows it: money and percentages as plain decimals, dates as Y-m-d. */
    public static function cell(string $type, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($type) {
            'money' => Money::toDecimalString((int) $value),
            'pct' => Money::toDecimalString((int) $value),
            'count' => (string) (int) $value,
            default => (string) $value,
        };
    }

    /** Neutralise a leading = + - @ tab or CR in text (money and percentages are numbers and never reach here as text). */
    public static function safe(mixed $value): string
    {
        $text = (string) $value;

        if ($text !== '' && preg_match('/^[=+\-@\t\r]/', $text) === 1 && preg_match('/^-?\d+(\.\d+)?$/', $text) !== 1) {
            return "'".$text;
        }

        return $text;
    }
}
