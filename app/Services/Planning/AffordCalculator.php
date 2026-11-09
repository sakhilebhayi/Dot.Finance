<?php

namespace App\Services\Planning;

use App\Models\Accounting\LedgerSetting;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * "Can I afford it?": runs the cash projection with and without a spend and says, in plain words, whether the projected
 * balance stays above the safety buffer the person chose. It is a projection from the business's own open invoices,
 * bills and recurring items; it never says "yes, you can" as a fact, and nothing is written to the books.
 */
class AffordCalculator
{
    public function __construct(private readonly CashProjection $projection) {}

    /**
     * @param  'once'|'monthly'  $mode
     * @param  list<array<string, mixed>>  $assumptions  scenario assumptions to project under (already normalised)
     * @return array<string, mixed>
     */
    public function check(LedgerContext $context, LedgerSetting $settings, CarbonImmutable $today, int $amountCents, CarbonImmutable $on, string $mode, int $months, int $bufferCents, array $assumptions = []): array
    {
        $horizon = $on->gt($today->addDays(90)) || ($mode === 'monthly' && $months > 3) ? 'months_12' : 'weeks_13';
        $without = $this->projection->project($context, $settings, $today, $horizon, $assumptions, $bufferCents);

        $spend = $mode === 'monthly'
            ? ['type' => 'new_cost', 'name' => 'The purchase', 'amount_cents' => $amountCents, 'start_on' => $on->toDateString(), 'months' => $months]
            : ['type' => 'one_off', 'name' => 'The purchase', 'direction' => 'out', 'amount_cents' => $amountCents, 'on' => $on->toDateString()];
        $with = $this->projection->project($context, $settings, $today, $horizon, array_merge($assumptions, [$spend]), $bufferCents);

        $currency = $settings->base_currency;
        $stays = $with['shortage'] === null;
        $headroom = $with['lowest']['balance_cents'] - $bufferCents;

        $headline = $stays
            ? 'On what is known today, your cash is projected to stay above your '.Money::format($bufferCents, $currency).' buffer. The lowest projected balance with this spend is '.Money::format($with['lowest']['balance_cents'], $currency).' on '.CarbonImmutable::parse($with['lowest']['date'])->format('j M Y').', '.Money::format($headroom, $currency).' above the buffer.'
            : 'With this spend, cash is projected to fall below your '.Money::format($bufferCents, $currency).' buffer on '.CarbonImmutable::parse($with['shortage']['date'])->format('j M Y').', to '.Money::format($with['shortage']['balance_cents'], $currency).'. The lowest projected balance is '.Money::format($with['lowest']['balance_cents'], $currency).' on '.CarbonImmutable::parse($with['lowest']['date'])->format('j M Y').'.';

        return [
            'stays_above_buffer' => $stays, 'headline' => $headline, 'horizon' => $horizon, 'currency' => $currency,
            'without' => $without, 'with' => $with, 'amount_cents' => $amountCents, 'buffer_cents' => $bufferCents,
            'headroom_cents' => $headroom, 'already_short' => $without['shortage'] !== null,
            'caveat' => 'This is a projection, not a guarantee. It uses open invoices, bills and recurring items as they stand today, and it does not know about money that has not been recorded.',
        ];
    }
}
