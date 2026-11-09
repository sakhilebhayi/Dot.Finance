<?php

namespace App\Services\Bank\Parsing;

/**
 * A minimal QIF reader for a bank account: records end with ^, D is the date, T the amount, P the payee, M the memo,
 * N the number. QIF has no balance, no currency and no date order, so the person states the date order like for a CSV.
 */
final class QifStatementParser
{
    public function parse(string $contents): RawStatement
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }
        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }

        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];
        $rows = [];
        $current = [];
        $index = 0;

        foreach ($lines as $text) {
            $text = rtrim($text);
            if ($text === '' || $text[0] === '!') {
                continue;
            }

            if ($text[0] === '^') {
                if ($current !== []) {
                    $index++;
                    $rows[] = $this->row($index, $current);
                }
                $current = [];

                continue;
            }

            $current[$text[0]] = trim(substr($text, 1));
        }

        if ($current !== []) {
            $index++;
            $rows[] = $this->row($index, $current);
        }

        $problems = $rows === [] ? ['No transactions were found in this QIF file.'] : [];

        return new RawStatement('qif', $rows, ['amount_locale_unknown' => true], $problems);
    }

    /** @param  array<string, string>  $record */
    private function row(int $line, array $record): array
    {
        $row = RawStatement::blankRow($line);
        // QIF writes a two-digit year after an apostrophe ("1/ 5'26"): make it a plain four-digit year.
        $date = isset($record['D']) ? preg_replace('/\s+/', '', preg_replace("/'(\\d{2})$/", '/20$1', $record['D'])) : null;
        $row['raw_date'] = $date === '' ? null : $date;
        $row['raw_amount'] = ($record['T'] ?? $record['U'] ?? null) ?: null;
        $payee = $record['P'] ?? '';
        $memo = $record['M'] ?? '';
        $description = trim($payee.(($memo !== '' && $memo !== $payee) ? ' '.$memo : ''));
        $row['description'] = $description === '' ? null : mb_substr($description, 0, 255);
        $row['reference'] = ($record['N'] ?? '') !== '' ? $record['N'] : null;

        return $row;
    }
}
