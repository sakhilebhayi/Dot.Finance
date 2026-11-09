<?php

namespace App\Services\Bank;

/**
 * Turns the words on a statement line or a ledger row into something comparable: a normalised string, the
 * significant words, and the document-looking numbers in it ("INV-0042", "inv 42" and "INV42" are one number).
 * Pure; no database.
 */
final class MatchText
{
    /** Words that say what kind of bank entry it is rather than what it is for. */
    private const STOP = [
        'the', 'and', 'for', 'from', 'with', 'payment', 'payments', 'pmt', 'pos', 'purchase', 'card', 'debit', 'credit', 'order',
        'transfer', 'trf', 'fee', 'eft', 'ibank', 'fnb', 'abs', 'ref', 'reference', 'inv', 'invoice', 'bill', 'cash', 'deposit',
        'withdrawal', 'online', 'internet', 'banking', 'branch', 'cheque', 'tran', 'trn', 'pty', 'ltd', 'number', 'acc', 'account',
        'immediate', 'instant', 'rtc', 'stop', 'cheq', 'sepa', 'swift', 'dep', 'cr', 'dr', 'cis',
    ];

    /**
     * @return array{text: string, tokens: list<string>, docs: list<string>}
     */
    public static function prepare(?string $description, ?string $reference = null): array
    {
        $raw = trim(($description ?? '').' '.($reference ?? ''));
        $text = trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($raw)));

        return ['text' => $text, 'tokens' => self::tokens($text), 'docs' => self::docs($raw)];
    }

    /** @return list<string> */
    public static function tokens(string $normalised): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $normalised, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];

        foreach ($parts as $part) {
            $isNumber = ctype_digit($part);

            if ($isNumber ? mb_strlen($part) < 4 : mb_strlen($part) < 3) {
                continue;
            }
            if (in_array($part, self::STOP, true)) {
                continue;
            }

            $out[$part] = true;
        }

        return array_keys($out);
    }

    /** @return list<string> e.g. ['INV42', 'PO1007'] */
    public static function docs(string $raw): array
    {
        if (preg_match_all('/(?<![A-Za-z0-9])([A-Za-z]{1,6})[\s\-\/#]?0*(\d{2,})(?![A-Za-z0-9])/', $raw, $m, PREG_SET_ORDER) < 1) {
            return [];
        }

        $out = [];
        foreach ($m as $hit) {
            $out[strtoupper($hit[1]).$hit[2]] = true;
        }

        return array_keys($out);
    }

    /** Whole days since 1970-01-01 for a Y-m-d date (so two dates subtract to a day count with no timezone). */
    public static function day(string $date): int
    {
        return intdiv((int) strtotime($date.' 00:00:00 UTC'), 86400);
    }
}
