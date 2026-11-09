<?php

namespace App\Services\Tax;

use App\Models\Purchases\ExpenseClaimLine;
use App\Models\Shared\Attachment;
use App\Support\Ledger\LedgerContext;
use Illuminate\Support\Collection;

/**
 * What the VAT review can know about input VAT that came from an expense claim. Purchases posts a claim as a 'bill' entry whose
 * source_id is "claim-<id>" and whose party is the claimant (an employee), so the two checks the review makes for a supplier invoice
 * do not apply as written: the VAT number that matters is the MERCHANT's, one per receipt line, and the file is the receipt, attached
 * to each line (or to the claim). A claim is documented when every line that carries VAT has a receipt, and its VAT numbers are in
 * order when every such line records the merchant's VAT number.
 */
final class ClaimEvidence
{
    /**
     * @param  Collection<int, object>  $entries  rows with entry_id, source_type, source_id
     * @return array<int, array{claim_id: int, documented: bool, vat_numbers: bool}> entry_id => evidence, only for entries that are claims
     */
    public function forEntries(LedgerContext $ctx, Collection $entries): array
    {
        $claimOf = [];
        foreach ($entries as $e) {
            if ($e->source_type === 'bill' && preg_match('/^claim-(\d+)$/', (string) $e->source_id, $m) === 1) {
                $claimOf[(int) $e->entry_id] = (int) $m[1];
            }
        }
        if ($claimOf === []) {
            return [];
        }

        $lines = LedgerContext::run($ctx, fn () => ExpenseClaimLine::query()->whereIn('expense_claim_id', array_unique($claimOf))->where('claimable_tax_cents', '>', 0)->get(['id', 'expense_claim_id', 'merchant_vat_number']))->groupBy('expense_claim_id');

        $lineFiles = Attachment::query()->forContext($ctx)->whereNull('archived_at')->where('attachable_type', (new ExpenseClaimLine)->getMorphClass())
            ->whereIn('attachable_id', $lines->flatten()->pluck('id')->all())->pluck('attachable_id')->map(fn ($id) => (int) $id)->flip();
        $claimFiles = Attachment::query()->forContext($ctx)->whereNull('archived_at')->where('attachable_type', 'expense_claim')
            ->whereIn('attachable_id', array_unique($claimOf))->pluck('attachable_id')->map(fn ($id) => (int) $id)->flip();

        $out = [];
        foreach ($claimOf as $entryId => $claimId) {
            $taxed = $lines->get($claimId, collect());
            $out[$entryId] = [
                'claim_id' => $claimId,
                'documented' => $claimFiles->has($claimId) || ($taxed->isNotEmpty() && $taxed->every(fn ($l) => $lineFiles->has((int) $l->id))),
                'vat_numbers' => $taxed->isNotEmpty() && $taxed->every(fn ($l) => filled($l->merchant_vat_number)),
            ];
        }

        return $out;
    }
}
