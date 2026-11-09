<?php

namespace App\Services\Intelligence\Handlers;

use App\Models\User;
use App\Services\Intelligence\Answer;
use App\Services\Intelligence\Intent;
use App\Services\Intelligence\RecognisedIntent;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;

/**
 * One family of questions. A handler reads the active ledger through existing services and returns an Answer
 * made only of what it found. It never writes: a draft is a proposal inside the Answer, stored by the assistant
 * as a pending draft, and nothing happens to the books until a person confirms it.
 */
abstract class Handler
{
    /** @return list<Intent> */
    abstract public function intents(): array;

    abstract public function handle(RecognisedIntent $question, LedgerContext $context, ?User $user = null): Answer;

    protected function money(int $cents, ?string $currency): string
    {
        return Money::format($cents, $currency);
    }

    /** A whole-number percentage of $whole, rounded half away from zero, with no float. */
    protected function percent(int $part, int $whole): int
    {
        return $whole === 0 ? 0 : Money::divideRound($part * 100, $whole);
    }

    protected function plural(int $n, string $one, string $many): string
    {
        return $n.' '.($n === 1 ? $one : $many);
    }

    /** @return list<array{label: string, href: string}> */
    protected function sources(array ...$links): array
    {
        $out = [];
        foreach ($links as [$label, $href]) {
            if (is_string($href) && $href !== '') {
                $out[] = ['label' => $label, 'href' => $href];
            }
        }

        return $out;
    }
}
