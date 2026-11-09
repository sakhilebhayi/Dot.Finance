<?php

namespace App\Services\Intelligence\Detectors;

use App\Models\Shared\Party;
use App\Services\Shared\PartyDuplicates;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/**
 * Two customers (or two suppliers) that look like one business entered twice. A split customer splits its balance, its
 * statement and its credit limit across two records, so it belongs under "What needs attention" until one is archived or
 * the pair is dismissed as genuinely different. Same matching rules as the warning on the add form (PartyDuplicates).
 */
final class DuplicatePartyDetector implements Detector
{
    public function __construct(private readonly PartyDuplicates $duplicates) {}

    public function kind(): string
    {
        return 'duplicate_party';
    }

    public function period(CarbonImmutable $asOf): string
    {
        return 'ongoing';
    }

    public function detect(LedgerContext $context, CarbonImmutable $asOf): array
    {
        $findings = [];
        foreach ($this->duplicates->pairs($context) as $pair) {
            /** @var array{0: Party, 1: Party} $parties */
            $parties = $pair['parties'];
            $ids = [$parties[0]->id, $parties[1]->id];
            sort($ids);
            $role = $pair['role'] === 'supplier' ? 'supplier' : 'customer';
            $href = fx_route($role === 'customer' ? 'sales.customers.show' : 'purchases.suppliers.show', [$parties[0]->id]) ?? fx_route('parties.show', [$parties[0]->id]);

            $findings[] = new Finding(
                $this->kind(),
                'parties:'.$ids[0].'-'.$ids[1],
                $this->period($asOf),
                'warning',
                "Two {$role}s look like one: {$parties[0]->name} and {$parties[1]->name}",
                "They have {$pair['reason']}. If they are the same business, their balances, statements and credit limits are split across two records. Archive the extra one or keep both if they really are different.",
                [
                    ['label' => $parties[0]->name, 'value' => $role.' #'.$parties[0]->id, 'href' => fx_route('parties.show', [$parties[0]->id])],
                    ['label' => $parties[1]->name, 'value' => $role.' #'.$parties[1]->id, 'href' => fx_route('parties.show', [$parties[1]->id])],
                ],
                $href,
            );
        }

        return $findings;
    }
}
