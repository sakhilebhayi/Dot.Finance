<?php

namespace App\Services\Intelligence;

/**
 * The check between a model's wording and the facts. A rewrite is accepted only when every number in it also
 * appears in the figures that were already computed (the sentence, facts, rows and notes), and it uses no
 * number word ("three", "double", "half") the computed text did not use. Digits are compared with separators
 * removed, so "ZAR 1,234.50" and "R1 234,50" are the same figure; anything else is rejected, and the plain
 * computed sentence is shown instead.
 */
final class NumberGuard
{
    private const WORDS = [
        'zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen',
        'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty',
        'ninety', 'hundred', 'thousand', 'million', 'billion', 'half', 'double', 'triple', 'twice', 'thrice', 'dozen', 'quarter',
    ];

    /** @return list<string> the numbers in $text, separators removed, leading zeros dropped (a date's "05" equals "5") */
    public static function numbers(string $text, bool $withParts = false): array
    {
        $text = str_replace(["\u{00A0}", "\u{202F}", "\u{2009}"], ' ', $text);
        preg_match_all('/\d+(?:[ ,.]\d+)*/u', $text, $m);

        $out = [];
        foreach ($m[0] as $token) {
            $out[] = ltrim(preg_replace('/\D/', '', $token) ?? '', '0') ?: '0';
            // Parts of a grouped number are only collected on request (never for the check itself): "234" is not "1 234".
            if ($withParts) {
                foreach (preg_split('/[ ,.]/', $token) ?: [] as $part) {
                    $out[] = ltrim(preg_replace('/\D/', '', $part) ?? '', '0') ?: '0';
                }
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<string> number words in $text */
    public static function words(string $text): array
    {
        preg_match_all('/\b('.implode('|', self::WORDS).')\b/iu', $text, $m);

        return array_values(array_unique(array_map('mb_strtolower', $m[1])));
    }

    /** @return list<string> everything wrong with $candidate; empty means it may be shown */
    public static function violations(string $candidate, string $figuresText): array
    {
        $problems = [];

        $allowed = array_flip(self::numbers($figuresText));
        foreach (self::numbers($candidate, false) as $n) {
            if (! isset($allowed[$n])) {
                $problems[] = "number {$n} is not in the computed figures";
            }
        }

        $allowedWords = array_flip(self::words($figuresText));
        foreach (self::words($candidate) as $w) {
            if (! isset($allowedWords[$w])) {
                $problems[] = "number word '{$w}' is not in the computed figures";
            }
        }

        if (preg_match('~https?://|\bwww\.|\[[^\]]*\]\([^)]*\)~i', $candidate) === 1) {
            $problems[] = 'it contains a link';
        }

        if (mb_strlen($candidate) > 700) {
            $problems[] = 'it is too long';
        }

        return $problems;
    }
}
