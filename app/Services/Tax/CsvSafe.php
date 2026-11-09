<?php

namespace App\Services\Tax;

/** CSV writing that cannot run a formula in a spreadsheet: a text cell that starts with = + - @ tab or CR is made literal. */
final class CsvSafe
{
    public static function cell(mixed $value): string
    {
        $text = (string) ($value ?? '');
        if ($text === '' || is_numeric($text)) {
            return $text;
        }

        return in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$text : $text;
    }

    /** @param  list<list<mixed>>  $rows */
    public static function render(array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($out, array_map([self::class, 'cell'], $row), ',', '"', '');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** Cents as a plain decimal string (1234.50). */
    public static function money(int $cents): string
    {
        return ($cents < 0 ? '-' : '').intdiv(abs($cents), 100).'.'.str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }
}
