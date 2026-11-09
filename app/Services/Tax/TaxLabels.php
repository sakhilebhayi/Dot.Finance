<?php

namespace App\Services\Tax;

/**
 * The words the tax pages use for states and rule keys, in one place so every page says the same thing in plain language.
 * Internal identifiers (rule keys, version numbers, hashes) are shown only behind a "Show working" disclosure, and then
 * through headline() so they read as words.
 */
final class TaxLabels
{
    public static function obligationStatus(string $status): string
    {
        return match ($status) {
            'applies' => 'Applies to you',
            'not_required' => 'Not required',
            'optional' => 'Optional',
            'review' => 'Needs a decision',
            'not_configured' => 'Not set up',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    /** Whether the status should draw the eye (something a person must do or decide). */
    public static function obligationNeedsAction(string $status): bool
    {
        return in_array($status, ['review', 'not_configured'], true);
    }

    /** "vat.registration.compulsory_threshold_cents" as "VAT registration: compulsory threshold". */
    public static function headline(string $key): string
    {
        $key = preg_replace('/_cents$/', '', $key) ?? $key;
        $parts = array_map(fn (string $p) => str_replace('_', ' ', $p), explode('.', $key));
        $text = implode(': ', $parts);
        $text = preg_replace_callback('/^(vat|cit|sbc|uif|sdl|paye|eti|itr\d+|irp6|emp\d+|coida)\b/i', fn ($m) => strtoupper($m[1]), $text) ?? $text;

        return ucfirst($text);
    }

    /**
     * A stored period label such as "Sep 2026 to Oct 2026 (category B)" in words a person can use: the SARS category letter
     * means nothing to an owner, the length of the period does. Labels without a category letter are returned as they are.
     */
    public static function plainPeriod(?string $label, mixed $start, mixed $end): string
    {
        $label = (string) $label;
        if (! preg_match('/\s*\(category [A-F]\)/', $label) || $start === null || $end === null) {
            return $label;
        }
        $months = (int) round(\Carbon\CarbonImmutable::parse($start)->diffInMonths(\Carbon\CarbonImmutable::parse($end)->addDay()));

        return preg_replace('/\s*\(category [A-F]\)/', ' ('.self::months(max(1, $months)).' period)', $label) ?? $label;
    }

    public static function months(int $n): string
    {
        return [1 => 'one-month', 2 => 'two-month', 3 => 'three-month', 6 => 'six-month', 12 => 'twelve-month'][$n] ?? $n.'-month';
    }
}
