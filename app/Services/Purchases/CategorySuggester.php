<?php

namespace App\Services\Purchases;

use App\Models\Purchases\BillLine;
use App\Support\Ledger\LedgerContext;
use Illuminate\Support\Facades\DB;

/**
 * A suggestion for the account and tax code of a new bill line, from what this ledger already did for the same supplier (and, when a
 * description is given, for the same words). It is only history counted: the most used account for that supplier. It is labelled
 * "Suggestion" wherever it is shown and is never applied without the person choosing it.
 */
final class CategorySuggester
{
    /** @return array{gl_account_id: int, tax_code: string, based_on: int, basis: string, label: string}|null */
    public function forSupplier(LedgerContext $context, int $partyId, ?string $description = null): ?array
    {
        return LedgerContext::run($context, function () use ($partyId, $description) {
            $query = fn () => BillLine::query()->whereHas('bill', fn ($q) => $q->where('party_id', $partyId)->where('kind', 'bill')->whereIn('status', ['open', 'partially_paid', 'paid']));

            $description = $description !== null ? mb_strtolower(trim($description)) : '';

            if ($description !== '') {
                $match = $this->top($query()->whereRaw('LOWER(description) = ?', [$description]));
                if ($match !== null) {
                    return $match + ['basis' => 'Suggestion', 'label' => 'Suggestion: you used this for "'.$description.'" '.$match['based_on'].' time'.($match['based_on'] === 1 ? '' : 's').' before.'];
                }
            }

            $match = $this->top($query());

            return $match === null ? null : $match + ['basis' => 'Suggestion', 'label' => 'Suggestion: the account most used for this supplier ('.$match['based_on'].' line'.($match['based_on'] === 1 ? '' : 's').').'];
        });
    }

    /** @return array{gl_account_id: int, tax_code: string, based_on: int}|null */
    private function top($query): ?array
    {
        $row = $query->select('gl_account_id', 'tax_code', DB::raw('COUNT(*) AS uses'))->groupBy('gl_account_id', 'tax_code')->orderByDesc('uses')->orderBy('gl_account_id')->first();

        return $row === null ? null : ['gl_account_id' => (int) $row->gl_account_id, 'tax_code' => (string) $row->tax_code, 'based_on' => (int) $row->uses];
    }
}
