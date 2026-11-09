<?php

namespace App\Services\Shared;

use App\Models\Shared\Party;
use App\Support\Ledger\LedgerContext;
use Illuminate\Support\Collection;

/**
 * Finds customers and suppliers that look like the same business entered twice. One definition serves the warning on the
 * "add a customer or supplier" form and the detector that raises it under "What needs attention", so they cannot disagree.
 *
 * Two parties of the same role (both customers, or both suppliers) are a likely pair when:
 *   - they share a tax number or an e-mail address;
 *   - their names are the same once capitals, punctuation and company endings (Pty, Ltd, CC...) are taken off; or
 *   - every word of the shorter name is in the longer ("Cape Green Importers" and "Cape Green Coffee Importers"); or
 *   - the names are one or two letters apart ("Woodstock Deli" and "Woodstok Deli").
 * It only ever suggests: it never merges, archives or changes anything.
 */
final class PartyDuplicates
{
    /** Company endings and filler words that say nothing about which business it is. */
    private const NOISE = ['pty', 'ltd', 'limited', 'cc', 'inc', 'co', 'company', 'the', 'and', 'of', 'sa', 'za', 'proprietary', 'holdings', 'group'];

    /** Lower-case, no punctuation, no company endings, single spaces. */
    public static function normalise(string $name): string
    {
        $lower = mb_strtolower(str_replace('&', ' and ', $name));
        $words = preg_split('/[^a-z0-9]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_values(array_diff($words, self::NOISE)));
    }

    /**
     * Why two parties look alike, or null when they do not.
     *
     * @param  array{name: string, tax_number?: ?string, email?: ?string}  $a
     * @param  array{name: string, tax_number?: ?string, email?: ?string}  $b
     */
    public static function reason(array $a, array $b): ?string
    {
        $taxA = self::digits($a['tax_number'] ?? null);
        if ($taxA !== '' && $taxA === self::digits($b['tax_number'] ?? null)) {
            return 'the same tax number';
        }
        $mailA = mb_strtolower(trim((string) ($a['email'] ?? '')));
        if ($mailA !== '' && $mailA === mb_strtolower(trim((string) ($b['email'] ?? '')))) {
            return 'the same e-mail address';
        }

        $na = self::normalise($a['name']);
        $nb = self::normalise($b['name']);
        if ($na === '' || $nb === '') {
            return null;
        }
        if ($na === $nb) {
            return 'the same name';
        }

        $ta = explode(' ', $na);
        $tb = explode(' ', $nb);
        [$short, $long] = count($ta) <= count($tb) ? [$ta, $tb] : [$tb, $ta];
        if (count($short) >= 2 && array_diff($short, $long) === []) {
            return 'a very similar name';
        }
        if (mb_strlen($na) >= 6 && mb_strlen($nb) >= 6 && levenshtein($na, $nb) <= 2) {
            return 'a very similar spelling';
        }

        return null;
    }

    /**
     * Pairs already in the ledger (active, not archived), same role, largest group first. Each: ['reason', 'role', 'parties' => [Party, Party]].
     *
     * @return list<array{reason: string, role: string, parties: list<Party>}>
     */
    public function pairs(LedgerContext $context): array
    {
        $out = [];
        foreach (['customer' => 'customers', 'supplier' => 'suppliers'] as $role => $scope) {
            /** @var Collection<int, Party> $parties */
            $parties = Party::query()->forContext($context)->{$scope}()->where('status', 'active')->orderBy('id')->get();
            $count = $parties->count();
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $reason = self::reason($this->attrs($parties[$i]), $this->attrs($parties[$j]));
                    if ($reason !== null) {
                        $out[] = ['reason' => $reason, 'role' => $role, 'parties' => [$parties[$i], $parties[$j]]];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Existing parties that look like one about to be added or renamed.
     *
     * @param  array{type?: string, name: string, tax_number?: ?string, email?: ?string}  $new
     * @return list<array{party: Party, reason: string}>
     */
    public function candidatesFor(array $new, ?int $ignoreId = null): array
    {
        $type = $new['type'] ?? 'customer';
        $roles = $type === 'both' ? ['customers', 'suppliers'] : [$type === 'supplier' ? 'suppliers' : 'customers'];

        $found = [];
        foreach ($roles as $scope) {
            foreach (Party::query()->{$scope}()->where('status', 'active')->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->get() as $existing) {
                $reason = self::reason($new, $this->attrs($existing));
                if ($reason !== null) {
                    $found[$existing->id] = ['party' => $existing, 'reason' => $reason];
                }
            }
        }

        return array_values($found);
    }

    /** @return array{name: string, tax_number: ?string, email: ?string} */
    private function attrs(Party $p): array
    {
        return ['name' => (string) $p->name, 'tax_number' => $p->tax_number, 'email' => $p->email];
    }

    private static function digits(?string $value): string
    {
        return preg_replace('/[^A-Za-z0-9]/', '', (string) $value) ?? '';
    }
}
