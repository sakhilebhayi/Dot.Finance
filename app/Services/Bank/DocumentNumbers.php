<?php

namespace App\Services\Bank;

/**
 * Does a statement narrative mention a document number? Pure.
 *
 * "INV-0042", "inv 42" and "INV42" are one number (MatchText::docs), which is right for a number with one run of digits. A number with
 * two runs ("BILL-2026-00003", year then sequence) reduces under that rule to its first run only, so every bill of the year would "be
 * mentioned" by any one of them. For such numbers the narrative must contain the whole number, ignoring case, spaces and punctuation.
 */
final class DocumentNumbers
{
    /** @param list<string> $docs the document-looking numbers already found in the narrative (MatchText::prepare) */
    public static function mentioned(string $text, string $number, array $docs): bool
    {
        if ($number === '') {
            return false;
        }

        if (preg_match_all('/\d+/', $number) > 1) {
            return str_contains(self::squash($text), self::squash($number));
        }

        $key = MatchText::docs($number)[0] ?? null;

        return $key !== null && in_array($key, $docs, true);
    }

    private static function squash(string $value): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));
    }
}
