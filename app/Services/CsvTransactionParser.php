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
     * Open the file and read its header; the rows come from a generator, one record at a time, so a file of any size
     * costs the same memory. The text is converted from Windows-1252 as it is read when it is not valid UTF-8.
     *
     * @return array{columns_found: array<string, bool>, rows: \Generator<int, array<string, mixed>>}
     */
    public function stream(string $path): array
    {
        $none = ['columns_found' => ['date' => false, 'description' => false, 'amount' => false, 'category' => false], 'rows' => (fn () => yield from [])()];
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return $none;
        }

        $bom = fread($handle, 3);
        $start = $bom === "\xEF\xBB\xBF" ? 3 : 0;

        // Valid UTF-8 from end to end? Checked a line at a time, so nothing is held.
        fseek($handle, $start);
        $utf8 = true;
        while (($line = fgets($handle)) !== false) {
            if (! mb_check_encoding($line, 'UTF-8')) {
                $utf8 = false;
                break;
            }
        }
        fseek($handle, $start);
        if (! $utf8 && ! @stream_filter_append($handle, 'convert.iconv.Windows-1252/UTF-8', STREAM_FILTER_READ)) {
            // no iconv: convert the whole (small, in practice) file in memory
            $contents = mb_convert_encoding((string) stream_get_contents($handle), 'UTF-8', 'Windows-1252');
            fclose($handle);
            $handle = fopen('php://temp', 'r+');
            fwrite($handle, $contents);
            rewind($handle);
        }

        $first = fgets($handle);
        if ($first === false || trim($first) === '') {
            fclose($handle);

            return $none;
        }
        $delimiter = $this->delimiter(rtrim($first, "\r\n"));
        $header = str_getcsv(rtrim($first, "\r\n"), $delimiter, '"', '');
        $columns = $this->mapColumns($header);

        $rows = (function () use ($handle, $delimiter, $columns) {
            $line = 1;
            try {
                while (($record = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                    $line++;
                    if (count($record) === 1 && trim((string) $record[0]) === '') {
                        continue; // blank line
                    }
                    yield [
                        'line' => $line,
                        'description' => $this->cell($record, $columns['description']),
                        'raw_date' => $this->cell($record, $columns['date']),
                        'raw_amount' => $this->cell($record, $columns['amount']),
                        'category_name' => $this->cell($record, $columns['category']),
                    ];
                }
            } finally {
                fclose($handle);
            }
        })();

        return ['columns_found' => array_map(fn ($index) => $index !== null, $columns), 'rows' => $rows];
    }

    /**
     * The whole file as an array. Fine for a few hundred rows; the importer uses stream().
     *
     * @return array{columns_found: array<string, bool>, rows: list<array<string, mixed>>}
     */
    public function read(string $path): array
    {
        $file = $this->stream($path);

        return ['columns_found' => $file['columns_found'], 'rows' => iterator_to_array($file['rows'], false)];
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
