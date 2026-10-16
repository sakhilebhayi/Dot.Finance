<?php

namespace App\Services;

use App\Models\Category;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

/**
 * Parses a bank/statement-style CSV into rows shaped like a Transaction,
 * without touching the database -- CsvImportController::store() is what
 * actually persists rows, after the user has reviewed the preview this
 * produces. No third-party CSV/spreadsheet package is used; PHP's own
 * fgetcsv() is enough for the plain comma-delimited exports this targets.
 */
class CsvTransactionParser
{
    /**
     * Header names this recognises, case-insensitively, per logical field.
     * The first matching column wins.
     */
    private const HEADER_ALIASES = [
        'date' => ['date', 'transaction date', 'posted date'],
        'description' => ['description', 'memo', 'payee', 'details', 'narrative'],
        'amount' => ['amount', 'value'],
        'category' => ['category'],
    ];

    /**
     * @return array{rows: array<int, array{line: int, description: ?string, amount: ?string, type: ?string, date: ?string, category_id: ?int, category_name: ?string, error: ?string}>, columns_found: array<string, bool>}
     */
    public function parse(UploadedFile $file, int $userId): array
    {
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            return ['rows' => [], 'columns_found' => []];
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);

            return ['rows' => [], 'columns_found' => []];
        }

        $columnIndex = $this->mapColumns($header);
        $categoriesByName = Category::where('user_id', $userId)->get()->keyBy(fn ($c) => mb_strtolower($c->name));

        $rows = [];
        $line = 1;

        while (($record = fgetcsv($handle)) !== false) {
            $line++;

            if ($this->isBlankRow($record)) {
                continue;
            }

            $rows[] = $this->parseRow($record, $columnIndex, $categoriesByName, $line);
        }

        fclose($handle);

        return [
            'rows' => $rows,
            'columns_found' => [
                'date' => $columnIndex['date'] !== null,
                'description' => $columnIndex['description'] !== null,
                'amount' => $columnIndex['amount'] !== null,
                'category' => $columnIndex['category'] !== null,
            ],
        ];
    }

    /**
     * @param  string[]  $header
     * @return array<string, int|null>
     */
    private function mapColumns(array $header): array
    {
        $normalized = array_map(fn ($h) => mb_strtolower(trim($h)), $header);
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

    private function isBlankRow(array $record): bool
    {
        return count($record) === 1 && trim((string) $record[0]) === '';
    }

    /**
     * @param  string[]  $record
     * @param  array<string, int|null>  $columnIndex
     * @param  Collection<string, Category>  $categoriesByName
     * @return array{line: int, description: ?string, amount: ?string, type: ?string, date: ?string, category_id: ?int, category_name: ?string, error: ?string}
     */
    private function parseRow(array $record, array $columnIndex, $categoriesByName, int $line): array
    {
        $description = $this->column($record, $columnIndex['description']);
        $rawAmount = $this->column($record, $columnIndex['amount']);
        $rawDate = $this->column($record, $columnIndex['date']);
        $categoryName = $this->column($record, $columnIndex['category']);

        $error = null;
        $amount = null;
        $type = null;
        $date = null;

        if (! $description) {
            $error = 'Missing description.';
        } elseif ($rawAmount === null || ! is_numeric(str_replace(',', '', $rawAmount))) {
            $error = 'Missing or invalid amount.';
        } elseif (! $rawDate || strtotime($rawDate) === false) {
            $error = 'Missing or invalid date.';
        } else {
            $numericAmount = (float) str_replace(',', '', $rawAmount);
            // This app stores amount as an always-positive magnitude with a
            // separate type enum (confirmed via ReserveRunwayCalculator's
            // totalExpense - totalIncome usage) -- a bank CSV's sign is the
            // only signal for which type an imported row is, so it's
            // consumed here rather than carried through as a negative amount.
            $type = $numericAmount < 0 ? 'expense' : 'income';
            $amount = number_format(abs($numericAmount), 2, '.', '');
            $date = date('Y-m-d', strtotime($rawDate));
        }

        $category = $categoryName ? $categoriesByName->get(mb_strtolower($categoryName)) : null;

        return [
            'line' => $line,
            'description' => $description,
            'amount' => $amount,
            'type' => $type,
            'date' => $date,
            'category_id' => $category?->id,
            'category_name' => $categoryName,
            'error' => $error,
        ];
    }

    private function column(array $record, ?int $index): ?string
    {
        if ($index === null || ! isset($record[$index])) {
            return null;
        }

        $value = trim($record[$index]);

        return $value === '' ? null : $value;
    }
}
