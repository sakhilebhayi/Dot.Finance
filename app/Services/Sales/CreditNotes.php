<?php

namespace App\Services\Sales;

use App\Models\Sales\SalesDocument;
use App\Models\Sales\SalesDocumentLine;
use App\Services\Sales\Support\ComputedDocument;
use App\Services\Sales\Support\SalesViolation;
use App\Support\Money;

/**
 * Credit and debit notes: documents that correct an issued invoice. They carry the invoice's tax treatment (the
 * rate at its time of supply, its exchange rate, its currency) and can never credit more than was charged.
 *
 *  - a credit note line adjusts one line of the invoice (parent_line_id), inherits that line's tax code and income
 *    account, and across all live credit notes may not exceed that line's net value;
 *  - across all live credit notes, the gross and the tax may not exceed the invoice's;
 *  - a debit note raises what the customer owes; it is a receivable in its own right.
 */
final class CreditNotes
{
    /**
     * Turn a person's credit-note lines into lines that follow the invoice: the tax code and income account of the
     * invoice line they adjust, whatever the form sent.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    public function inherit(SalesDocument $parent, array $lines): array
    {
        $parentLines = $parent->lines()->get()->keyBy('id');
        $out = [];

        foreach (array_values($lines) as $i => $line) {
            $id = (int) ($line['parent_line_id'] ?? 0);
            $source = $parentLines->get($id)
                ?? throw new SalesViolation('Line '.($i + 1).' of a credit note must adjust a line of the invoice it corrects.', 'line_invalid', ['line' => $i + 1]);

            $out[] = ['tax_code' => $source->tax_code, 'income_account_id' => $source->income_account_id, 'parent_line_id' => $source->id] + $line;
        }

        return $out;
    }

    /**
     * The credit that may still be given on an invoice: per line (net) and in total (gross and tax), counting every
     * issued credit note that is not void, optionally leaving one out (the one being issued).
     *
     * @return array{lines: array<int, int>, gross: int, tax: int, net: int}
     */
    public function remaining(SalesDocument $parent, ?int $exceptDocumentId = null): array
    {
        $context = $parent->ledgerContext();
        $credits = SalesDocument::query()->forContext($context)->where('parent_id', $parent->id)->where('kind', 'credit_note')
            ->whereNotNull('sealed_at')->where('status', '<>', 'void')
            ->when($exceptDocumentId, fn ($q) => $q->whereKeyNot($exceptDocumentId))->pluck('id');

        $used = SalesDocumentLine::query()->forContext($context)->whereIn('document_id', $credits)->get()->groupBy('parent_line_id');
        $lines = [];
        foreach ($parent->lines()->get() as $line) {
            $lines[$line->id] = (int) $line->net_cents - (int) ($used->get($line->id)?->sum('net_cents') ?? 0);
        }

        $creditedGross = (int) SalesDocument::query()->forContext($context)->whereIn('id', $credits)->sum('total_cents');
        $creditedTax = (int) SalesDocument::query()->forContext($context)->whereIn('id', $credits)->sum('tax_cents');
        $creditedNet = (int) SalesDocument::query()->forContext($context)->whereIn('id', $credits)->sum('net_cents');

        return [
            'lines' => $lines,
            'gross' => (int) $parent->total_cents - $creditedGross,
            'tax' => (int) $parent->tax_cents - $creditedTax,
            'net' => (int) $parent->net_cents - $creditedNet,
        ];
    }

    /** @throws SalesViolation */
    public function assertWithinLimits(SalesDocument $parent, ComputedDocument $computed, ?int $exceptDocumentId = null): void
    {
        $left = $this->remaining($parent, $exceptDocumentId);

        $byLine = [];
        foreach ($computed->lines as $line) {
            $byLine[$line->parentLineId] = ($byLine[$line->parentLineId] ?? 0) + $line->netCents;
        }

        foreach ($byLine as $parentLineId => $net) {
            if (! array_key_exists((int) $parentLineId, $left['lines'])) {
                throw new SalesViolation('A credit line points at a line that is not on the invoice.', 'credit_limit');
            }
            if ($net > $left['lines'][(int) $parentLineId]) {
                $position = $parent->lines()->whereKey($parentLineId)->value('position');
                throw new SalesViolation("Line {$position} of the invoice can be credited by at most ".Money::format($left['lines'][(int) $parentLineId], $parent->currency).' more (net of tax). The credit notes already issued and this one add up to more than was charged.', 'credit_limit');
            }
        }

        if ($computed->totalCents > $left['gross'] || $computed->taxCents > $left['tax']) {
            throw new SalesViolation('This credit note, with the ones already issued, would credit more than the invoice charged ('.Money::format($left['gross'], $parent->currency).' left to credit, of which '.Money::format($left['tax'], $parent->currency).' is tax).', 'credit_limit');
        }
    }

    /** Lines that credit what is left on every line of the invoice. @return list<array<string, mixed>> */
    public function remainingLines(SalesDocument $parent): array
    {
        $left = $this->remaining($parent);
        $rows = [];

        foreach ($parent->lines()->get() as $line) {
            $net = $left['lines'][$line->id];
            if ($net <= 0) {
                continue;
            }

            // Whole line left: copy it exactly (same quantity, price, discount), so its tax is the invoice's tax to the cent.
            if ($net === (int) $line->net_cents && ! $parent->prices_include_tax) {
                $rows[] = ['description' => $line->description, 'unit' => $line->unit, 'quantity' => $line->quantity(), 'unit_cents' => (int) $line->unit_cents,
                    'discount_bp' => (int) $line->discount_bp, 'discount_cents' => (int) $line->discount_cents, 'parent_line_id' => $line->id];
            } else {
                // Part of it was credited: one unit for what is left. Inclusive pricing credits the gross left.
                $rows[] = ['description' => 'Credit: '.$line->description, 'unit' => null, 'quantity' => '1', 'unit_cents' => $parent->prices_include_tax ? $this->grossLeft($line, $net) : $net,
                    'discount_bp' => 0, 'discount_cents' => 0, 'parent_line_id' => $line->id];
            }
        }

        return $rows;
    }

    private function grossLeft(SalesDocumentLine $line, int $netLeft): int
    {
        // inclusive pricing: scale the gross by the share of the net still uncredited
        return Money::mulDivRound((int) $line->gross_cents, $netLeft, max(1, (int) $line->net_cents));
    }
}
