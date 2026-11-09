<?php

namespace App\Services\Intelligence\Detectors;

use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/**
 * Looks at one ledger's recorded data and reports what is unusual, with the rows that prove it. Statistical rules
 * only (medians, ratios, exact matches): no model decides that something is wrong. A detector must be cheap enough
 * to run weekly on a large ledger, must read only through $context, and must say nothing when it lacks the history
 * to judge (a quiet detector is better than a noisy one).
 */
interface Detector
{
    /** Stable name of the kind of finding, stored with it. */
    public function kind(): string;

    /** The window a finding belongs to ("2026-11", "2026-Q4", "ongoing"). A finding from this window that is no longer true is resolved. */
    public function period(CarbonImmutable $asOf): string;

    /** @return list<Finding> */
    public function detect(LedgerContext $context, CarbonImmutable $asOf): array;
}
