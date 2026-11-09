<?php

namespace App\Services\Bank;

use App\Models\Bank\BankRule;
use App\Support\Ledger\LedgerContext;
use Illuminate\Support\Collection;

/**
 * "If the narrative contains X then category Y", applied as SUGGESTIONS: the engine says which rule fits a statement line
 * and the screen shows the rule's sentence next to the offered category; nothing is created or categorised until a person
 * confirms. The first rule (lowest priority number, then oldest) that fits wins, so a person can order them.
 */
final class BankRuleEngine
{
    /** @param  Collection<int, BankRule>  $rules  active rules, already in the order they are tried */
    public function __construct(private readonly Collection $rules) {}

    public static function for(LedgerContext $context, int $accountId): self
    {
        $rules = BankRule::query()->forContext($context)->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('account_id')->orWhere('account_id', $accountId))
            ->with('category')->orderBy('priority')->orderBy('id')->get();

        return new self($rules);
    }

    public function isEmpty(): bool
    {
        return $this->rules->isEmpty();
    }

    /** The rule that fits this line (an object with description, reference and amount_cents), or null. */
    public function match(object $line): ?BankRule
    {
        $description = self::normalise((string) ($line->description ?? ''));
        $reference = self::normalise((string) ($line->reference ?? ''));
        $cents = (int) $line->amount_cents;

        foreach ($this->rules as $rule) {
            if ($rule->direction === 'in' && $cents <= 0) {
                continue;
            }
            if ($rule->direction === 'out' && $cents >= 0) {
                continue;
            }
            if ($rule->min_cents !== null && abs($cents) < $rule->min_cents) {
                continue;
            }
            if ($rule->max_cents !== null && abs($cents) > $rule->max_cents) {
                continue;
            }

            $haystacks = match ($rule->match_field) {
                'description' => [$description],
                'reference' => [$reference],
                default => [$description, $reference],
            };

            $needle = self::normalise($rule->pattern);

            foreach ($haystacks as $haystack) {
                if ($haystack === '') {
                    continue;
                }
                $hit = match ($rule->operator) {
                    'equals' => $haystack === $needle,
                    'starts_with' => str_starts_with($haystack, $needle),
                    default => str_contains($haystack, $needle),
                };
                if ($hit) {
                    return $rule;
                }
            }
        }

        return null;
    }

    public static function normalise(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($text)));
    }
}
