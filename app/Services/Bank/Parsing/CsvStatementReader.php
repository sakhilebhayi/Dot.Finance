<?php

namespace App\Services\Bank\Parsing;

/**
 * Reads a bank CSV into cells and applies a column mapping the person chose. Handles a UTF-8 byte-order mark,
 * comma / semicolon / tab delimiters and Windows-1252 exports (common from South African banks). The mapping names a
 * column for each field by its position; nothing is guessed silently: suggestMapping() only pre-fills the form from
 * the header names, and the person confirms it.
 *
 * Amounts can be one signed column, a debit and a credit column, or an unsigned column plus a Dr/Cr type column.
 */
final class CsvStatementReader
{
    public const FIELDS = ['date', 'description', 'reference', 'amount', 'debit', 'credit', 'type', 'balance'];

    private const ALIASES = [
        'date' => ['date', 'transaction date', 'posted date', 'posting date', 'value date', 'booking date', 'trans date'],
        'description' => ['description', 'narrative', 'details', 'memo', 'payee', 'transaction description', 'particulars', 'statement description'],
        'reference' => ['reference', 'ref', 'ref no', 'reference number', 'cheque number', 'check number', 'beneficiary reference'],
        'amount' => ['amount', 'value', 'transaction amount', 'amount (zar)', 'net amount'],
        'debit' => ['debit', 'debits', 'money out', 'withdrawal', 'withdrawals', 'paid out', 'dr'],
        'credit' => ['credit', 'credits', 'money in', 'deposit', 'deposits', 'paid in', 'cr'],
        'type' => ['type', 'dr/cr', 'debit/credit', 'transaction type', 'cr/dr'],
        'balance' => ['balance', 'running balance', 'closing balance', 'available balance', 'balance after'],
    ];

    /** @return array{delimiter: string, records: list<list<string>>} every record, header included, as strings */
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

        $delimiter = $this->delimiter($this->firstLines($contents));
        $records = [];

        while (($record = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if (count($record) === 1 && trim((string) $record[0]) === '') {
                continue; // blank line
            }
            $records[] = array_map(fn ($cell) => trim((string) $cell), $record);
        }

        fclose($handle);

        return ['delimiter' => $delimiter, 'records' => $records];
    }

    /**
     * Pre-fill the mapping from header names. Returns the mapping and whether the first record looks like a header.
     *
     * @param  list<list<string>>  $records
     * @return array{has_header: bool, columns: array<string, ?int>}
     */
    public function suggestMapping(array $records): array
    {
        $columns = array_fill_keys(self::FIELDS, null);
        $header = $records[0] ?? [];
        $normalised = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $header);

        foreach (self::ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                $index = array_search($alias, $normalised, true);
                if ($index !== false && ! in_array($index, array_filter($columns, fn ($v) => $v !== null), true)) {
                    $columns[$field] = $index;

                    break;
                }
            }
        }

        $hasHeader = $columns['date'] !== null || $columns['amount'] !== null || $columns['description'] !== null || ($columns['debit'] !== null && $columns['credit'] !== null);

        return ['has_header' => $hasHeader, 'columns' => $columns];
    }

    /**
     * @param  list<list<string>>  $records
     * @param  array{has_header: bool, columns: array<string, ?int>}  $mapping
     */
    public function toRaw(array $records, array $mapping): RawStatement
    {
        $columns = $mapping['columns'];
        $rows = [];
        $line = 0;
        $problems = [];

        foreach ($records as $i => $record) {
            $line = $i + 1;

            if ($i === 0 && $mapping['has_header']) {
                continue;
            }

            $row = RawStatement::blankRow($line);
            $row['raw_date'] = $this->cell($record, $columns['date'] ?? null);
            $row['description'] = $this->cell($record, $columns['description'] ?? null);
            $row['reference'] = $this->cell($record, $columns['reference'] ?? null);
            $row['raw_amount'] = $this->cell($record, $columns['amount'] ?? null);
            $row['raw_debit'] = $this->cell($record, $columns['debit'] ?? null);
            $row['raw_credit'] = $this->cell($record, $columns['credit'] ?? null);
            $row['raw_type'] = $this->cell($record, $columns['type'] ?? null);
            $row['raw_balance'] = $this->cell($record, $columns['balance'] ?? null);

            $rows[] = $row;
        }

        if ($columns['date'] === null) {
            $problems[] = 'Choose the column that holds the date.';
        }
        if ($columns['description'] === null) {
            $problems[] = 'Choose the column that holds the description.';
        }
        if ($columns['amount'] === null && ($columns['debit'] === null && $columns['credit'] === null)) {
            $problems[] = 'Choose the amount column, or the debit and credit columns.';
        }

        return new RawStatement('csv', $rows, [], $problems);
    }

    /** @param  list<string>  $record */
    private function cell(array $record, ?int $index): ?string
    {
        if ($index === null || ! isset($record[$index])) {
            return null;
        }

        $value = trim((string) $record[$index]);

        return $value === '' ? null : $value;
    }

    private function firstLines(string $contents): string
    {
        return implode("\n", array_slice(preg_split('/\r\n|\r|\n/', $contents) ?: [], 0, 5));
    }

    private function delimiter(string $sample): string
    {
        $counts = [',' => substr_count($sample, ','), ';' => substr_count($sample, ';'), "\t" => substr_count($sample, "\t")];
        arsort($counts);

        return reset($counts) > 0 ? array_key_first($counts) : ',';
    }
}
