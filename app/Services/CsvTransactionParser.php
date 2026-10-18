<?php

namespace App\Services;

/**
 * Reads a bank/statement-style CSV into raw rows, exactly as written: no dates
 * or amounts are interpreted here. Interpretation needs the user's chosen date
 * format and number format, so it happens in TransactionImporter.
 *
 * Handles a UTF-8 byte-order mark, comma / semicolon / tab delimiters, and
 * Windows-1252 exports (common from South African banks). No third-party CSV
 * package: PHP's own fgetcsv() is enough for these plain exports.
 */
class CsvTransactionParser
{
    /** Header names recognised, case-insensitively, per logical field; first match wins. */
    private const HEADER_ALIASES = [
        'date' => ['date', 'transaction date', 'posted date', 'posting date'],
        'description' => ['description', 'memo', 'payee', 'details', 'narrative', 'reference'],
        'amount' => ['amount', 'value'],
        'category' => ['category'],
    ];

    /**
     * @return array{columns_found: array<string, bool>, rows: list<array{line: int, description: ?string, raw_date: ?string, raw_amount: ?string, category_name: ?string}>}
     */
    public function read(string $path): array
    {
        $contents = (string) @file_get_contents($path);

        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $contents);
        rewind($handle);

        $delimiter = $this->delimiter(strtok($contents, "\n") ?: '');
        $header = fgetcsv($handle, 0, $delimiter, '"', '');

        if ($header === false || $header === [null]) {
            fclose($handle);

            return ['columns_found' => ['date' => false, 'description' => false, 'amount' => false, 'category' => false], 'rows' => []];
        }

        $columns = $this->mapColumns($header);

        $rows = [];
        $line = 1;

        while (($record = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $line++;

            if (count($record) === 1 && trim((string) $record[0]) === '') {
                continue; // blank line
            }

            $rows[] = [
                'line' => $line,
                'description' => $this->cell($record, $columns['description']),
                'raw_date' => $this->cell($record, $columns['date']),
                'raw_amount' => $this->cell($record, $columns['amount']),
                'category_name' => $this->cell($record, $columns['category']),
            ];
        }

        fclose($handle);

        return [
            'columns_found' => array_map(fn ($index) => $index !== null, $columns),
            'rows' => $rows,
        ];
    }

    private function delimiter(string $headerLine): string
    {
        $counts = [',' => substr_count($headerLine, ','), ';' => substr_count($headerLine, ';'), "\t" => substr_count($headerLine, "\t")];
        arsort($counts);

        return reset($counts) > 0 ? array_key_first($counts) : ',';
    }

    /**
     * @param  array<int, string|null>  $header
     * @return array<string, int|null>
     */
    private function mapColumns(array $header): array
    {
        $normalized = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $header);
        $map = [];

        foreach (self::HEADER_ALIASES as $field => $aliases) {
            $map[$field] = null;

            foreach ($aliases as $alias) {
                $index = array_search($alias, $normalized, true);

                if ($index !== false) {
                    $map[$field] = $index;

                    break;
                }
            }
        }

        return $map;
    }

    /** @param  array<int, string|null>  $record */
    private function cell(array $record, ?int $index): ?string
    {
        if ($index === null || ! isset($record[$index])) {
            return null;
        }

        $value = trim((string) $record[$index]);

        return $value === '' ? null : $value;
    }
}
