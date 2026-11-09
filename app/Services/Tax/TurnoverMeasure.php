<?php

namespace App\Services\Tax;

use App\Models\Transaction;
use App\Services\Accounting\GlSync;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/**
 * How much the business supplied over a twelve-month window, for the registration-threshold questions.
 * It is an ESTIMATE: from the journals when accounting is on (income accounts, leaving out exempt and out-of-scope
 * tagged lines and "other income"), else from the cash ledger's counted income in the jurisdiction currency.
 */
final class TurnoverMeasure
{
    public function __construct(private readonly GlSync $sync, private readonly GlTaxLines $lines) {}

    /**
     * @return array{cents: int, source: string, from: string, to: string, notes: list<string>}
     */
    public function trailingTwelveMonths(LedgerContext $ctx, CarbonImmutable $asOf, string $currency = 'ZAR'): array
    {
        $to = $asOf->toDateString();
        $from = $asOf->subYear()->addDay()->toDateString();
        $notes = [];

        if ($this->sync->isEnabled($ctx)) {
            $m = $this->lines->taxableSupplies($ctx, $from, $to);
            if ($m['untagged_cents'] > 0) {
                $notes[] = 'Includes income lines with no tax code, which may not all be taxable supplies.';
            }
            $notes[] = 'Other income (for example interest), exempt supplies and non-supplies are not counted.';

            return ['cents' => max(0, $m['taxable_cents']), 'source' => 'books', 'from' => $from, 'to' => $to, 'notes' => $notes];
        }

        $cents = (int) Transaction::query()->forContext($ctx)->counted()
            ->where('type', 'income')->whereNull('transfer_group')
            ->where(fn ($q) => $q->whereNull('currency')->orWhere('currency', $currency))
            ->whereBetween('date', [$from, $to])->sum('amount_cents');
        $notes[] = 'Accounting is not switched on, so this is the income recorded in the cash ledger (all income, not only taxable supplies).';

        return ['cents' => $cents, 'source' => 'cash ledger', 'from' => $from, 'to' => $to, 'notes' => $notes];
    }
}
