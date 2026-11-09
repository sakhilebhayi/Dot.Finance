<?php

namespace App\Services\Tax;

use App\Services\Shared\Tax\ResolvedRate;
use App\Services\Shared\Tax\ResolvedRule;
use App\Services\Shared\TaxRuleBook;
use Carbon\CarbonInterface;

/**
 * One calculation's view of the tax rule book: every rule or rate it resolves is remembered, so the result can name
 * exactly which versions it used (tax_rules#12, tax_rates#3), their confidence and their source. Never falls back
 * to "the current value": an unresolvable rule throws NoValidTaxRule and the calculation stops.
 */
final class RuleSession
{
    /** @var array<string, array<string, mixed>> */
    private array $used = [];

    public function __construct(private readonly TaxRuleBook $book, private readonly string $jurisdiction = 'ZA') {}

    public function jurisdiction(): string
    {
        return $this->jurisdiction;
    }

    public function rule(string $key, CarbonInterface|string $on, string $qualifier = ''): ResolvedRule
    {
        $rule = $this->book->resolve($this->jurisdiction, $key, $on, $qualifier);
        $this->used[$rule->version()] = [
            'version' => $rule->version(),
            'key' => $rule->key.($rule->qualifier !== '' ? " [{$rule->qualifier}]" : ''),
            'confidence' => $rule->confidence,
            'legal_status' => $rule->legalStatus,
            'valid_from' => $rule->validFrom->toDateString(),
            'valid_to' => $rule->validTo?->toDateString(),
            'source_url' => $rule->sourceUrl,
            'source_ref' => $rule->sourceRef,
            'override' => $rule->isOverride,
        ];

        return $rule;
    }

    public function find(string $key, CarbonInterface|string $on, string $qualifier = ''): ?ResolvedRule
    {
        $rule = $this->book->find($this->jurisdiction, $key, $on, $qualifier);
        if ($rule !== null) {
            $this->rule($key, $on, $qualifier);
        }

        return $rule;
    }

    /** @var array<string, ResolvedRate> resolved rates by key and day: a VAT review asks the same question for thousands of lines */
    private array $rates = [];

    public function rate(string $rateKey, CarbonInterface|string $on): ResolvedRate
    {
        $day = $on instanceof CarbonInterface ? $on->toDateString() : substr($on, 0, 10);
        if (isset($this->rates[$rateKey.'|'.$day])) {
            return $this->rates[$rateKey.'|'.$day];
        }

        $rate = $this->rates[$rateKey.'|'.$day] = $this->book->rate($this->jurisdiction, $rateKey, $on);
        $this->used[$rate->version()] = [
            'version' => $rate->version(),
            'key' => $rate->rateKey,
            'confidence' => $rate->confidence,
            'legal_status' => $rate->legalStatus,
            'valid_from' => $rate->validFrom->toDateString(),
            'valid_to' => $rate->validTo?->toDateString(),
            'source_url' => $rate->sourceUrl,
            'source_ref' => null,
            'override' => $rate->isOverride,
        ];

        return $rate;
    }

    /** @return list<array<string, mixed>> */
    public function versions(): array
    {
        return array_values($this->used);
    }

    /** The least certain confidence among the rules used (low < medium < high). */
    public function lowestConfidence(): string
    {
        $order = ['low' => 0, 'medium' => 1, 'high' => 2];
        $lowest = 'high';
        foreach ($this->used as $u) {
            if ($order[$u['confidence']] < $order[$lowest]) {
                $lowest = $u['confidence'];
            }
        }

        return $lowest;
    }

    public function book(): TaxRuleBook
    {
        return $this->book;
    }
}
