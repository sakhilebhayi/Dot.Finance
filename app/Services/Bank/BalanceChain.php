<?php

namespace App\Services\Bank;

/**
 * Works out a statement's opening and closing balance from a running-balance column, but only when the column proves
 * itself: every row's balance must equal the previous balance plus that row's amount, in file order or in reverse
 * (banks export newest first as often as oldest first). Anything less returns nulls: a balance is never invented.
 */
final class BalanceChain
{
    /**
     * @param  list<array{cents: ?int, balance: ?int, error: ?string}>  $rows  in file order
     * @return array{opening: ?int, closing: ?int}
     */
    public static function derive(array $rows): array
    {
        $usable = array_values(array_filter($rows, fn ($r) => $r['error'] === null));

        if ($usable === [] || count($usable) !== count($rows)) {
            return ['opening' => null, 'closing' => null];
        }

        foreach ($usable as $r) {
            if ($r['balance'] === null || $r['cents'] === null) {
                return ['opening' => null, 'closing' => null];
            }
        }

        foreach ([$usable, array_reverse($usable)] as $chronological) {
            $ok = true;
            for ($i = 1, $n = count($chronological); $i < $n; $i++) {
                if ($chronological[$i - 1]['balance'] + $chronological[$i]['cents'] !== $chronological[$i]['balance']) {
                    $ok = false;

                    break;
                }
            }

            if ($ok) {
                return ['opening' => $chronological[0]['balance'] - $chronological[0]['cents'], 'closing' => $chronological[count($chronological) - 1]['balance']];
            }
        }

        return ['opening' => null, 'closing' => null];
    }
}
