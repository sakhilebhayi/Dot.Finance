<?php

namespace App\Services\Sales;

use App\Models\Sales\SalesDocument;
use App\Models\Sales\SalesDocumentLine;
use App\Services\Accounting\PostingLine;
use App\Services\Accounting\PostingRequest;
use App\Services\Sales\Support\PostingGroups;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * How a sales document becomes journal lines. Nothing here touches the database except reading the document's
 * own lines, so the rules can be read in one place.
 *
 *   tax invoice, debit note:  Dr receivables (gross)        Cr income (net, per account and tax code)   Cr output VAT (tax, per tax code)
 *   credit note:              Cr receivables (gross)        Dr income (net)                             Dr output VAT (tax)
 *
 * The amounts come from PostingGroups, the same fold the document was worked out with, and are checked against
 * the base totals stored on the document: a document that no longer adds up is refused, never posted.
 */
final class SalesPosting
{
    public function request(SalesDocument $document, ?int $postedBy = null): PostingRequest
    {
        if (! $document->postsToBooks()) {
            throw new LogicException('A '.$document->kind.' does not reach the books.');
        }

        $lines = $document->lines()->get();
        $folded = PostingGroups::fold($lines->map(fn (SalesDocumentLine $l) => [
            'income' => $l->income_account_id ?? 'sales', 'tax_code_id' => $l->tax_code_id, 'tax_code' => $l->tax_code, 'net' => (int) $l->net_cents, 'tax' => (int) $l->tax_cents,
        ])->all(), $document->fx_rate_e8 !== null ? (int) $document->fx_rate_e8 : null);

        if ($folded['base_net'] !== (int) $document->base_net_cents || $folded['base_tax'] !== (int) $document->base_tax_cents) {
            throw new LogicException("Document {$document->displayRef()} does not add up to its stored base totals; refusing to post it.");
        }

        $credit = $document->isCredit();
        $foreign = $document->isForeign();
        $currency = $document->currency;
        $rate = $document->fx_rate_e8 !== null ? (int) $document->fx_rate_e8 : null;
        $party = (int) $document->party_id;
        $label = $document->title().' '.$document->displayRef();

        $posting = [];

        // receivables: the whole amount, against the customer
        $posting[] = new PostingLine(
            'ar', $credit ? 0 : (int) $document->base_total_cents, $credit ? (int) $document->base_total_cents : 0,
            $foreign ? $currency : null, $foreign ? (int) $document->total_cents : null, $foreign ? $rate : null,
            partyId: $party, description: $label,
        );

        foreach ($folded['income'] as $g) {
            if ($g['base'] === 0) {
                continue;
            }
            $posting[] = $this->side($g['account'], $g['base'], $credit, $foreign, $currency, $g['net'], $rate, $g['tax_code_id'], 'Sales: '.$label);
        }

        foreach ($folded['tax'] as $g) {
            if ($g['base'] === 0) {
                continue;
            }
            $posting[] = $this->side('vat_output', $g['base'], $credit, $foreign, $currency, $g['tax'], $rate, $g['tax_code_id'], 'Output VAT: '.$label);
        }

        $source = $credit ? 'credit_note' : 'invoice';
        $name = $document->party?->name ?? 'customer';

        return new PostingRequest(
            CarbonImmutable::parse($document->issue_date->toDateString()), $source, $document->id, 1,
            $label.' to '.$name, $posting, $postedBy,
        );
    }

    /**
     * A revenue-side line. An invoice credits it; a credit note debits it. A group whose net is negative (a
     * discount line larger than the rest of its group) goes on the opposite side, so a line is never negative.
     */
    private function side(string|int $account, int $base, bool $creditNote, bool $foreign, string $currency, int $foreignAmount, ?int $rate, ?int $taxCodeId, string $description): PostingLine
    {
        $debit = $creditNote ? $base > 0 : $base < 0;

        return new PostingLine(
            $account, $debit ? abs($base) : 0, $debit ? 0 : abs($base),
            $foreign ? $currency : null, $foreign ? abs($foreignAmount) : null, $foreign ? $rate : null,
            taxCodeId: $taxCodeId, description: $description,
        );
    }
}
