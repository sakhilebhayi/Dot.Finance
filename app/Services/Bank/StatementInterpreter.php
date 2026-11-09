<?php

namespace App\Services\Bank;

use App\Services\Bank\Parsing\RawStatement;
use App\Support\ImportDates;
use App\Support\Money;
use InvalidArgumentException;

/**
 * Turns the raw cells of a statement into dates and signed cents using the person's stated date order and number
 * format. Money in is positive, money out negative. Nothing is guessed: a cell it cannot read exactly is an error on
 * that row (the row is listed in the preview and skipped), and an ambiguous date order is handed back to the person.
 */
final class StatementInterpreter
{
    /**
     * @return array{date_format: ?string, date_ambiguous: bool, detected_date_format: ?string, amount_locale: ?string, amount_ambiguous: bool, detected_amount_locale: ?string, rows: list<array<string, mixed>>}
     */
    public function interpret(RawStatement $raw, ?string $dateFormat, ?string $amountLocale, bool $invert = false): array
    {
        $detectedDate = ImportDates::detect(array_values(array_filter(array_column($raw->rows, 'raw_date'))));

        $amountSamples = [];
        foreach ($raw->rows as $row) {
            foreach (['raw_amount', 'raw_debit', 'raw_credit', 'raw_balance'] as $key) {
                if ($row[$key] !== null) {
                    $amountSamples[] = $row[$key];
                }
            }
        }
        $detectedAmount = Money::detectLocale($amountSamples);

        $dateFormat = $raw->hints['date_format'] ?? (in_array($dateFormat, ImportDates::FORMATS, true) ? $dateFormat : $detectedDate['format']);
        $amountLocale = $raw->hints['amount_locale'] ?? (in_array($amountLocale, ['en', 'eu'], true) ? $amountLocale : $detectedAmount['locale']);

        $rows = [];
        foreach ($raw->rows as $row) {
            $rows[] = $this->row($row, $dateFormat, $amountLocale, $invert);
        }

        return [
            'date_format' => $dateFormat,
            'date_ambiguous' => ! isset($raw->hints['date_format']) && $detectedDate['ambiguous'],
            'detected_date_format' => $detectedDate['format'],
            'amount_locale' => $amountLocale,
            'amount_ambiguous' => ! isset($raw->hints['amount_locale']) && $detectedAmount['ambiguous'],
            'detected_amount_locale' => $detectedAmount['locale'],
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function row(array $raw, ?string $dateFormat, ?string $amountLocale, bool $invert): array
    {
        $row = [
            'line' => $raw['line'], 'date' => null, 'cents' => null, 'description' => $raw['description'] ?? $raw['reference'],
            'reference' => ($raw['description'] ?? null) !== null ? ($raw['reference'] ?? null) : null,
            'external_id' => $raw['external_id'] ?? null, 'balance' => null, 'error' => null,
            'raw_date' => $raw['raw_date'], 'raw_amount' => $raw['raw_amount'] ?? ($raw['raw_credit'] ?? $raw['raw_debit']),
        ];

        if ($row['description'] === null) {
            $row['error'] = 'Missing description.';
        } else {
            $row['description'] = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $row['description'])), 0, 255);
        }

        if ($raw['raw_date'] === null) {
            $row['error'] ??= 'Missing date.';
        } elseif ($dateFormat !== null) {
            $row['date'] = ImportDates::parse($raw['raw_date'], $dateFormat);

            if ($row['date'] === null) {
                $row['error'] ??= "\"{$raw['raw_date']}\" is not a valid ".ImportDates::LABELS[$dateFormat].' date.';
            } elseif (! $this->plausible($row['date'])) {
                $row['error'] ??= "{$row['date']} is not a plausible statement date.";
                $row['date'] = null;
            }
        }

        if ($amountLocale !== null) {
            try {
                $cents = $this->amount($raw, $amountLocale);
                if ($cents === null) {
                    $row['error'] ??= 'Missing amount.';
                } elseif ($cents === 0) {
                    $row['error'] ??= 'The amount is zero.';
                } else {
                    $row['cents'] = $invert ? -$cents : $cents;
                }
            } catch (InvalidArgumentException $e) {
                $row['error'] ??= $e->getMessage();
            }

            if (($raw['raw_balance'] ?? null) !== null) {
                try {
                    $row['balance'] = Money::parseLocalized($raw['raw_balance'], $amountLocale);
                } catch (InvalidArgumentException) {
                    $row['balance'] = null; // a balance that cannot be read is simply not used
                }
            }
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $raw
     *
     * @throws InvalidArgumentException
     */
    private function amount(array $raw, string $locale): ?int
    {
        if ($raw['raw_amount'] !== null) {
            $cents = Money::parseLocalized($raw['raw_amount'], $locale);

            if ($raw['raw_type'] !== null) {
                $sign = $this->typeSign($raw['raw_type']);

                if ($sign === null) {
                    throw new InvalidArgumentException("\"{$raw['raw_type']}\" is not a debit or credit marker.");
                }

                return $sign * abs($cents);
            }

            return $cents;
        }

        $debit = $raw['raw_debit'] !== null ? abs(Money::parseLocalized($raw['raw_debit'], $locale)) : 0;
        $credit = $raw['raw_credit'] !== null ? abs(Money::parseLocalized($raw['raw_credit'], $locale)) : 0;

        if ($debit > 0 && $credit > 0) {
            throw new InvalidArgumentException('Both a debit and a credit are filled in on one row.');
        }
        if ($raw['raw_debit'] === null && $raw['raw_credit'] === null) {
            return null;
        }

        return $credit - $debit;
    }

    /** -1 for a debit marker, +1 for a credit marker, null for anything else. */
    private function typeSign(string $type): ?int
    {
        return match (mb_strtolower(trim($type))) {
            'dr', 'd', 'debit', 'db', 'withdrawal', 'out' => -1,
            'cr', 'c', 'credit', 'deposit', 'in' => 1,
            default => null,
        };
    }

    private function plausible(string $date): bool
    {
        $year = (int) substr($date, 0, 4);

        return $year >= 1990 && $date <= now()->addDay()->toDateString();
    }
}
