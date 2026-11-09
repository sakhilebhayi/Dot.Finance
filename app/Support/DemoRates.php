<?php

namespace App\Support;

use App\Models\Shared\FxRate;
use App\Services\Shared\FxMath;
use App\Services\Shared\FxService;
use App\Support\Ledger\LedgerContext;

/**
 * LOCAL DEMO DATA ONLY. A fictional USD to ZAR rate every five days for thirteen months, recorded through the real exchange-rate
 * service as a ledger rate, so the demo company's dollar account can be journalled. One place for it: the demo commands used to
 * each carry their own copy, or deleted the dollar account instead (which left one-leg transfers standing against Suspense).
 */
final class DemoRates
{
    /** Records the rates unless the ledger already has USD to ZAR rates. Returns how many it recorded. */
    public static function ensure(LedgerContext $ctx): int
    {
        $exists = LedgerContext::run($ctx, fn () => FxRate::query()->where('from_currency', 'USD')->where('to_currency', 'ZAR')->exists());
        if ($exists) {
            return 0;
        }

        $fx = app(FxService::class);
        $n = 0;
        $day = now()->startOfDay()->subMonths(13);
        for ($i = 0; $day->lte(now()); $i++, $day = $day->copy()->addDays(5)) {
            $fx->record('USD', 'ZAR', FxMath::parseRate(sprintf('%.2f', 17.60 + (($i * 7) % 23) * 0.06)), $day->toDateString(), 'manual', null, 'Demo rate (not a market rate)', context: $ctx);
            $n++;
        }

        return $n;
    }
}
