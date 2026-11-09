<?php

namespace App\Services\Bank\Parsing;

/**
 * A statement file read exactly as written: no date or amount is interpreted here, because that needs the
 * person's chosen date order and number format. $rows keep the cells as strings; $hints are what the format itself
 * states (an OFX file says its dates are year-first and its amounts use a dot), so those choices need no guess.
 */
final class RawStatement
{
    /**
     * @param  list<array{line: int, raw_date: ?string, description: ?string, reference: ?string, external_id: ?string, raw_amount: ?string, raw_debit: ?string, raw_credit: ?string, raw_balance: ?string, raw_type: ?string}>  $rows
     * @param  array{date_format?: string, amount_locale?: string, currency?: string, closing_cents?: int, opening_cents?: int, date_from?: string, date_to?: string}  $hints
     * @param  list<string>  $problems  things wrong with the file as a whole (not a row)
     */
    public function __construct(
        public readonly string $format,
        public readonly array $rows,
        public readonly array $hints = [],
        public readonly array $problems = [],
    ) {}

    public static function blankRow(int $line): array
    {
        return ['line' => $line, 'raw_date' => null, 'description' => null, 'reference' => null, 'external_id' => null, 'raw_amount' => null, 'raw_debit' => null, 'raw_credit' => null, 'raw_balance' => null, 'raw_type' => null];
    }
}
