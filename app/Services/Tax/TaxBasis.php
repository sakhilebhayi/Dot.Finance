<?php

namespace App\Services\Tax;

/**
 * How a figure on a tax screen came to be. Every figure carries exactly one of these, so a person always knows
 * whether they are looking at arithmetic on their books, a projection, their own entry or a rule-based hint.
 */
final class TaxBasis
{
    public const CALCULATED = 'calculated';

    public const ESTIMATE = 'estimate';

    public const ENTERED = 'entered';

    public const SUGGESTION = 'suggestion';

    public const ALL = [self::CALCULATED, self::ESTIMATE, self::ENTERED, self::SUGGESTION];

    public static function label(string $basis): string
    {
        return match ($basis) {
            self::CALCULATED => 'Calculated',
            self::ESTIMATE => 'Estimate',
            self::ENTERED => 'You entered',
            self::SUGGESTION => 'Suggestion',
            default => throw new \InvalidArgumentException("Unknown tax basis {$basis}."),
        };
    }

    public static function meaning(string $basis): string
    {
        return match ($basis) {
            self::CALCULATED => 'Arithmetic on the entries in your books with the rule versions named alongside.',
            self::ESTIMATE => 'A projection or a simplified calculation. The real assessment can differ.',
            self::ENTERED => 'A figure a person typed in. Dot.Finance has not checked it against anything.',
            self::SUGGESTION => 'A rule-based hint for you to review. It is not advice and nothing is applied without you.',
            default => '',
        };
    }

    /** The footer wherever interpretation is involved. */
    public const DISCLAIMER = 'This is information, not professional tax advice. Check anything that matters with a registered tax practitioner or with SARS.';

    /** The banner on every return working paper. */
    public const NOT_SUBMITTED = 'PREPARED, NOT SUBMITTED. Dot.Finance does not submit anything to SARS: a person files this on eFiling or at a branch.';
}
