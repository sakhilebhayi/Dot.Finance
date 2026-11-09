<?php

namespace App\Services\Tax\Jurisdiction;

use Carbon\CarbonImmutable;

/** A country the architecture can host but whose rules have NOT been entered. Nothing is calculated for it. */
final class NotConfiguredJurisdiction implements Jurisdiction
{
    public function __construct(private readonly string $code, private readonly string $name, private readonly string $currency) {}

    public function code(): string
    {
        return $this->code;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function entityTypes(): array
    {
        return [];
    }

    public function obligations(): array
    {
        return [];
    }

    public function vatCategories(): array
    {
        return [];
    }

    public function taxYear(string $legalEntity, int $financialYearEndMonth, CarbonImmutable $date): array
    {
        throw new \LogicException("{$this->name} is not configured: no tax year convention has been entered.");
    }

    public function notes(): array
    {
        return ["{$this->name} is not configured. Its tax rules have not been entered, so Dot.Finance calculates nothing for it."];
    }
}
