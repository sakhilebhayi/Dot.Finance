<?php

namespace App\Services\Tax;

use App\Services\Shared\TaxRuleBook;
use App\Support\Ledger\LedgerContext;

/** Starts a RuleSession for a ledger (so a ledger's own overrides, if any, are honoured and nothing leaks across ledgers). */
final class TaxRules
{
    public function __construct(private readonly TaxRuleBook $book) {}

    public function session(?LedgerContext $context = null, string $jurisdiction = 'ZA'): RuleSession
    {
        return new RuleSession($this->book->forContext($context ?? LedgerContext::current()), $jurisdiction);
    }
}
