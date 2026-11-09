<?php

namespace App\Services\Accounting;

use App\Services\Shared\FxService;
use App\Support\Ledger\LedgerContext;
use App\Support\Shared\Exceptions\NoFxRate;
use Carbon\CarbonImmutable;

/**
 * The FxRateProvider the accounting layer was promised: rates from the stored exchange-rate history (fx_rates).
 * A rate older than a week is not used: the movement is deferred instead (and the books say so), because a
 * guessed rate is worse than a visible gap. Without this binding any foreign-currency cash row can never be posted.
 */
final class FxServiceRateProvider implements FxRateProvider
{
    public const MAX_AGE_DAYS = 7;

    public function __construct(private readonly FxService $fx) {}

    public function rate(LedgerContext $context, string $from, string $to, CarbonImmutable $on): ?int
    {
        if ($from === $to) {
            return self::ONE;
        }

        try {
            return $this->fx->forContext($context)->rate($from, $to, $on, self::MAX_AGE_DAYS)->rateE8;
        } catch (NoFxRate) {
            return null;
        }
    }
}
