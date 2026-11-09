<?php

namespace App\Services\Intelligence;

/** What kind of statement an answer or finding is. Shown on every one, so nobody reads an estimate as a fact. */
enum Basis: string
{
    /** Arithmetic on the ledger's own recorded data. */
    case Calculated = 'calculated';

    /** A projection or a rule of thumb: depends on assumptions the answer states. */
    case Estimate = 'estimate';

    /** The wording was produced by a language model from facts that were already computed. */
    case AiSummary = 'ai_summary';

    public function label(): string
    {
        return match ($this) {
            self::Calculated => 'Calculated',
            self::Estimate => 'Estimate',
            self::AiSummary => 'AI-generated summary',
        };
    }

    public function explanation(): string
    {
        return match ($this) {
            self::Calculated => 'Worked out from the entries recorded in this ledger. No guessing.',
            self::Estimate => 'A projection from recorded data and the assumptions stated with it. It is not a promise.',
            self::AiSummary => 'The figures were calculated from your ledger; a language model only reworded them, and every number in the wording was checked against the calculation.',
        };
    }
}
