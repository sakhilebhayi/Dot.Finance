<?php

namespace App\Services\Tax\Jurisdiction;

/** The jurisdictions Dot.Finance knows about. Only a pack that says isConfigured() is ever calculated with. */
final class JurisdictionRegistry
{
    /** @var array<string, Jurisdiction> */
    private array $packs = [];

    public function __construct()
    {
        $this->register(new ZaJurisdiction);
        foreach ([['GB', 'United Kingdom', 'GBP'], ['US', 'United States', 'USD'], ['KE', 'Kenya', 'KES'], ['NG', 'Nigeria', 'NGN'], ['BW', 'Botswana', 'BWP']] as [$code, $name, $currency]) {
            $this->register(new NotConfiguredJurisdiction($code, $name, $currency));
        }
    }

    public function register(Jurisdiction $pack): void
    {
        $this->packs[$pack->code()] = $pack;
    }

    public function get(string $code): Jurisdiction
    {
        return $this->packs[$code] ?? throw new \InvalidArgumentException("Unknown jurisdiction {$code}.");
    }

    public function has(string $code): bool
    {
        return isset($this->packs[$code]);
    }

    /** @return array<string, Jurisdiction> */
    public function all(): array
    {
        return $this->packs;
    }

    /** @return array<string, Jurisdiction> */
    public function configured(): array
    {
        return array_filter($this->packs, fn (Jurisdiction $p) => $p->isConfigured());
    }
}
