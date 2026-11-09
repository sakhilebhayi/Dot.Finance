<?php

namespace App\Services\Reporting;

/**
 * What every report produces, whatever it is: a header, columns, rows, reconciliation checks and notes. The page,
 * the CSV, the spreadsheet and the print view are all drawn from this one shape, so they cannot disagree.
 *
 * Column: ['key' => string, 'label' => string, 'type' => 'text'|'money'|'pct'|'count'|'date', 'projected' => bool].
 * Row:    ['type' => 'section'|'line'|'subtotal'|'total'|'info', 'label' => string, 'depth' => int, 'cells' => [colKey => scalar|null],
 *          'drill' => [colKey => query-string params for /reports/drill], 'href' => ?string (a link for the label)].
 * Money cells are integers in minor units, percentages are basis points, nothing is a float.
 * Check:  ['key', 'label', 'ok' => bool, 'difference_cents' => int, 'detail' => string, optional 'currency' => ?string (the currency of the difference; null when it spans several)].
 */
final class ReportResult
{
    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $checks
     * @param  list<string>  $notes
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public array $header,
        public array $columns,
        public array $rows,
        public array $checks = [],
        public array $notes = [],
        public ?string $empty = null,
    ) {}

    /**
     * When set, the rows are produced one at a time by this generator factory instead of being held in $rows. Only an export
     * asks for that (a general ledger of a year can be hundreds of thousands of lines); the page and everything that reads
     * $rows is unchanged. The result is not for caching: it holds a closure.
     *
     * @var (\Closure(): iterable<int, array<string, mixed>>)|null
     */
    public ?\Closure $rowStream = null;

    /** The rows to write: the stream when there is one, otherwise the array. @return iterable<int, array<string, mixed>> */
    public function eachRow(): iterable
    {
        return $this->rowStream !== null ? ($this->rowStream)() : $this->rows;
    }

    public function rowCount(): int
    {
        return count(array_filter($this->rows, fn ($r) => in_array($r['type'], ['line', 'subtotal', 'total'], true)));
    }

    public function failedChecks(): array
    {
        return array_values(array_filter($this->checks, fn ($c) => ! $c['ok']));
    }

    /** The value of a cell, for tests and for the exporters. */
    public function cell(string $label, string $column): mixed
    {
        foreach ($this->rows as $row) {
            if (($row['label'] ?? null) === $label) {
                return $row['cells'][$column] ?? null;
            }
        }

        return null;
    }

    public function row(string $label): ?array
    {
        foreach ($this->rows as $row) {
            if (($row['label'] ?? null) === $label) {
                return $row;
            }
        }

        return null;
    }

    /** A plain-array copy, for caching and JSON. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key, 'title' => $this->title, 'header' => $this->header, 'columns' => $this->columns, 'rows' => $this->rows,
            'checks' => $this->checks, 'notes' => $this->notes, 'empty' => $this->empty,
        ];
    }
}
