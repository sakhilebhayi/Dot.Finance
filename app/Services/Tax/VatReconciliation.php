<?php

namespace App\Services\Tax;

use App\Services\Tax\Dto\ComputedReturn;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/**
 * Proves the return against the books. The books say how much VAT moved through the VAT output and input accounts in the
 * period (all lines, tagged or not, settlement entries left out); the return says what the tagged lines add up to. The
 * difference between them must be explained line by line, and anything left over blocks the return from being "ready".
 *
 *   GL net VAT      = output account credits less debits, less input account debits less credits
 *   Return from books = box 20 of the return computed from the books alone (no manual adjustments)
 *   Explained       = untagged VAT lines + lines left out of the return on purpose (denied claims, codes with no box)
 *                     + the net of cancelled entry/reversal pairs (zero when the pair mirrors)
 *   Unexplained     = difference less explained: must be zero
 *
 * Manual adjustments are shown apart: they are in the return and, by design, not in the books.
 */
final class VatReconciliation
{
    public function __construct(private readonly GlTaxLines $lines) {}

    /**
     * @return array<string, mixed>
     */
    public function forPeriod(LedgerContext $ctx, CarbonImmutable $from, CarbonImmutable $to, ComputedReturn $booksOnly, ?ComputedReturn $withAdjustments = null): array
    {
        $glLines = $this->lines->vatAccountLines($ctx, $from->toDateString(), $to->toDateString());

        $glOutput = (int) $glLines->where('system_key', 'vat_output')->sum(fn ($l) => $l->credit_cents - $l->debit_cents);
        $glInput = (int) $glLines->where('system_key', 'vat_input')->sum(fn ($l) => $l->debit_cents - $l->credit_cents);
        $glNet = $glOutput - $glInput;
        $returnNet = $booksOnly->amount('20');
        $difference = $glNet - $returnNet;

        $used = [];
        foreach ($booksOnly->boxes as $box) {
            foreach ($box['contributors'] as $c) {
                if ($c['type'] === 'line') {
                    $used[$c['id']] = true;
                }
            }
        }

        $effect = fn ($l) => $l->system_key === 'vat_output' ? $l->credit_cents - $l->debit_cents : -($l->debit_cents - $l->credit_cents);
        $untagged = [];
        $leftOut = [];
        $paired = [];
        $pairNet = 0;
        $reversalPairIds = $this->cancelledEntryIds($glLines, $from, $to);

        foreach ($glLines as $l) {
            if (isset($used[$l->line_id])) {
                continue;
            }
            if (isset($reversalPairIds[$l->entry_id])) {
                $pairNet += $effect($l);
                $paired[] = (int) $l->line_id;

                continue;
            }
            if ($l->tax_code_id === null) {
                $untagged[] = ['line_id' => (int) $l->line_id, 'entry_id' => (int) $l->entry_id, 'entry_number' => (int) $l->entry_number, 'date' => $l->entry_date, 'cents' => $effect($l), 'source' => $l->source_type.' '.$l->source_id];

                continue;
            }
            $leftOut[] = ['line_id' => (int) $l->line_id, 'entry_id' => (int) $l->entry_id, 'entry_number' => (int) $l->entry_number, 'date' => $l->entry_date, 'cents' => $effect($l), 'source' => $l->source_type.' '.$l->source_id];
        }

        $explained = [];
        if ($untagged !== []) {
            $explained[] = ['label' => 'VAT lines with no tax code (not in any box)', 'cents' => array_sum(array_column($untagged, 'cents')), 'lines' => $untagged];
        }
        if ($leftOut !== []) {
            $explained[] = ['label' => 'Tagged VAT lines left out on purpose (denied claims, codes with no box)', 'cents' => array_sum(array_column($leftOut, 'cents')), 'lines' => $leftOut];
        }
        if ($paired !== []) {
            $explained[] = ['label' => 'Entries cancelled by their own reversal in this period', 'cents' => $pairNet, 'lines' => []];
        }

        $explainedTotal = array_sum(array_column($explained, 'cents'));
        $unexplained = $difference - $explainedTotal;

        return [
            'gl_output_cents' => $glOutput,
            'gl_input_cents' => $glInput,
            'gl_net_cents' => $glNet,
            'return_from_books_cents' => $returnNet,
            'difference_cents' => $difference,
            'explained' => $explained,
            'explained_cents' => $explainedTotal,
            'unexplained_cents' => $unexplained,
            'ties' => $unexplained === 0,
            'untagged_count' => count($untagged),
            'adjustments_cents' => $withAdjustments ? $withAdjustments->amount('20') - $returnNet : 0,
        ];
    }

    /**
     * The entries that are a reversal and its original, both inside the period.
     *
     * @return array<int, true>
     */
    private function cancelledEntryIds($glLines, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ids = [];
        $entryDates = [];
        foreach ($glLines as $l) {
            $entryDates[$l->entry_id] = $l->entry_date;
        }
        foreach ($glLines as $l) {
            if ($l->reverses_entry_id !== null && isset($entryDates[$l->reverses_entry_id])) {
                $ids[(int) $l->entry_id] = true;
                $ids[(int) $l->reverses_entry_id] = true;
            }
        }

        return $ids;
    }
}
