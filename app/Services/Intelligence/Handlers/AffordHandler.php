<?php

namespace App\Services\Intelligence\Handlers;

use App\Contracts\Intelligence\AffordabilitySource;
use App\Models\User;
use App\Services\Intelligence\Answer;
use App\Services\Intelligence\Basis;
use App\Services\Intelligence\Intent;
use App\Services\Intelligence\RecognisedIntent;
use App\Support\Ledger\LedgerContext;

/**
 * "Can I afford R5,000?" Arithmetic on the projected lowest balance over the next 30 days, never a recommendation.
 * It needs an amount; without one it asks for it instead of guessing.
 */
final class AffordHandler extends Handler
{
    private const DAYS = 30;

    public function __construct(private readonly AffordabilitySource $source) {}

    public function intents(): array
    {
        return [Intent::CanIAfford];
    }

    public function handle(RecognisedIntent $question, LedgerContext $context, ?User $user = null): Answer
    {
        $link = $this->sources(['Forecast', fx_route('forecast.index')]);

        if ($question->amountCents === null) {
            return Answer::noData(Intent::CanIAfford, 'I need an amount to check. Ask it like "Can I afford R5,000?" and I will test it against your projected cash.', $link);
        }

        $projection = $this->source->projection($context, self::DAYS);

        if ($projection === null) {
            return Answer::noData(Intent::CanIAfford, "I don't have that information: no accounts have been added yet, so there is no cash to test the amount against.", $link);
        }

        $c = $projection['currency'];
        $amount = $question->amountCents;
        $lowestAfter = $projection['lowest_cents'] - $amount;
        $verdict = $lowestAfter >= 0 ? 'yes' : 'no';

        $sentence = $verdict === 'yes'
            ? 'On your recorded cash, yes: after spending '.$this->money($amount, $c).' your lowest balance in the next '.self::DAYS.' days would still be '.$this->money($lowestAfter, $c).'.'
            : 'On your recorded cash, not comfortably: after spending '.$this->money($amount, $c).' your balance would dip to '.$this->money($lowestAfter, $c).' (below zero) within the next '.self::DAYS.' days.';

        return new Answer(
            Intent::CanIAfford,
            Answer::ANSWERED,
            $sentence,
            [
                ['label' => "Balance now ({$c})", 'value' => $this->money($projection['balance_cents'], $c)],
                ['label' => 'Known recurring payments in the next '.self::DAYS.' days', 'value' => $this->money($projection['commitments_cents'], $c)],
                ['label' => 'Lowest balance without the new spend', 'value' => $this->money($projection['lowest_cents'], $c).($projection['lowest_date'] ? ' on '.$projection['lowest_date'] : '')],
                ['label' => 'Amount tested', 'value' => $this->money($amount, $c)],
                ['label' => 'Lowest balance after it', 'value' => $this->money($lowestAfter, $c), 'tone' => $lowestAfter < 0 ? 'variance' : 'neutral'],
            ],
            sources: $link,
            basis: Basis::Estimate,
            notes: [$projection['basis_note'], 'This is arithmetic on your own recorded data. It is not financial advice and not a promise about future cash.'],
        );
    }
}
