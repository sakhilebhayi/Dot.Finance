<?php

namespace App\Services\Tax;

use App\Models\Accounting\JournalEntry;
use App\Models\Tax\TaxReturnAdjustment;
use App\Services\Tax\Dto\ComputedReturn;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Computes a VAT201 (SARS layout rev 11, fields 1 to 20, diesel totals optional) from the journal lines that carry a tax code.
 *
 * HOW A LINE FINDS ITS BOX
 *  - A tagged line on the VAT output account is output tax; on the VAT input account it is input tax.
 *  - A tagged line on an income account is a supply (the net value); on an expense or asset account it is a purchase.
 *  - The tax code says which box: box_output (1, 1A, 2, 2A, 3) for supplies, box_input (14, 14A, 15, 15A) for input tax.
 *  - Field 1 and 1A are VAT-INCLUSIVE (net plus the VAT on the same entry); field 4/4A is the booked output VAT.
 *    Fields 14 to 15A are the booked input VAT.
 *  - A NEGATIVE amount (a credit note issued, a reversal in a later period) is not netted into the supply fields: the
 *    VAT of a sales-side negative goes to field 18 (credit notes issued) and of a purchase-side negative to field 12
 *    (credit notes received), as the SARS guide lays out. The value of such a supply is left out of fields 1 to 3
 *    (recorded as a decision: the guide's treatment of the value fields for credit notes is unverified).
 *  - An entry and its reversal inside the same period cancel and are both left out.
 *
 * The VAT is the VAT BOOKED, not recomputed; a check beside it compares each entry's booked VAT with the rate in force on
 * the entry's own date (so a period that straddles a rate change is checked correctly) and flags a difference larger than
 * rounding. Manual adjustments (bad debts, apportionment, ...) are added to their boxes as contributors with their reason.
 */
final class Vat201Builder
{
    /** Boxes a person may adjust by hand. The calculated totals (6, 8, 9, 13, 19, 20) can only change through their inputs. */
    public const ADJUSTABLE = ['1', '1A', '2', '2A', '3', '4', '4A', '5', '7', '10', '11', '12', '14', '14A', '15', '15A', '16', '17', '18', '25', '29', '33', '37'];

    /** Diesel refund totals, optional (litres detail is not modelled). */
    private const DIESEL = ['25' => 'Diesel refund total: on land', '29' => 'Diesel refund total: offshore', '33' => 'Diesel refund total: rail and harbour', '37' => 'Diesel refund total: peak power plant'];

    private const ORDER = ['1', '1A', '2', '2A', '3', '4', '4A', '5', '6', '7', '8', '9', '10', '11', '12', '13', '14', '14A', '15', '15A', '16', '17', '18', '19', '20', '25', '29', '33', '37', '38'];

    /** Control accounts whose tagged lines are not supplies or purchases. */
    private const CONTROL_KEYS = ['ar', 'ap', 'bank', 'cash', 'vat_control', 'loans_payable', 'suspense'];

    public function __construct(private readonly TaxRules $rules, private readonly GlTaxLines $lines) {}

    /**
     * @param  iterable<TaxReturnAdjustment>  $adjustments  the active (not voided) adjustments of the return
     */
    public function build(LedgerContext $ctx, CarbonImmutable $from, CarbonImmutable $to, iterable $adjustments = [], ?CarbonImmutable $postedUntil = null): ComputedReturn
    {
        $rules = $this->rules->session($ctx);
        $layout = collect($rules->rule('vat201.fields', $to, 'rev 11')->json())->keyBy('code');
        $rateBpAtEnd = $rules->rate('ZA_VAT_STD', $to)->rateBp;
        $accommodationBp = $rules->rule('vat.accommodation.long_stay_pct_bp', $to)->basisPoints();

        $tagged = $this->lines->tagged($ctx, $from->toDateString(), $to->toDateString(), $postedUntil?->format('Y-m-d H:i:s'));
        $tagged = $this->withoutCancelledPairs($ctx, $tagged, $from, $to);

        $issues = [];
        $boxes = [];
        $add = function (string $code, int $cents, array $contributors) use (&$boxes): void {
            $boxes[$code]['amount'] = ($boxes[$code]['amount'] ?? 0) + $cents;
            foreach ($contributors as $c) {
                $boxes[$code]['contributors'][] = $c;
            }
        };

        foreach ($this->groups($tagged, $issues) as $g) {
            $this->evaluate($g, $rules, $rateBpAtEnd, $add, $issues);
        }

        foreach ($adjustments as $adj) {
            $add($adj->box_code, (int) $adj->amount_cents, [[
                'type' => 'adjustment', 'id' => $adj->id, 'entry_id' => null, 'entry_number' => null, 'date' => null,
                'description' => (TaxReturnAdjustment::KINDS[$adj->kind] ?? $adj->kind).': '.$adj->reason,
                'amount_cents' => (int) $adj->amount_cents, 'note' => 'You entered this adjustment.',
            ]]);
        }

        $v = fn (string $code): int => (int) ($boxes[$code]['amount'] ?? 0);
        $calc = [];
        $calc['6'] = Money::mulDivRound($v('5'), $accommodationBp, 10_000);
        $calc['8'] = $calc['6'] + $v('7');
        $calc['9'] = Money::mulDivRound($calc['8'], $rateBpAtEnd, 10_000);
        $calc['13'] = $v('4') + $v('4A') + $calc['9'] + $v('11') + $v('12');
        $calc['19'] = $v('14') + $v('14A') + $v('15') + $v('15A') + $v('16') + $v('17') + $v('18');
        $calc['20'] = $calc['13'] - $calc['19'];
        $diesel = $v('25') + $v('29') + $v('33') + $v('37');
        $hasDiesel = $diesel !== 0;
        if ($hasDiesel) {
            $calc['38'] = $calc['20'] - $diesel;
        }

        $out = [];
        foreach (self::ORDER as $code) {
            $meta = $layout[$code] ?? null;
            if (in_array($code, ['25', '29', '33', '37'], true) && $v($code) === 0) {
                continue;
            }
            if ($code === '38' && ! $hasDiesel) {
                continue;
            }
            $isCalc = array_key_exists($code, $calc);
            $label = $meta['label'] ?? (self::DIESEL[$code] ?? 'Total after the diesel refund (field 20 less the diesel totals)');
            $out[] = [
                'code' => $code,
                'label' => $label,
                'kind' => $isCalc ? 'calc' : (in_array($code, ['25', '29', '33', '37'], true) ? 'manual' : 'input'),
                'amount_cents' => $isCalc ? $calc[$code] : $v($code),
                'contributors' => $boxes[$code]['contributors'] ?? [],
                'formula' => $meta['formula'] ?? ($code === '38' ? 'f20 - (f25 + f29 + f33 + f37)' : null),
                'note' => $this->boxNote($code),
            ];
        }

        $output = $calc['13'];
        $input = $calc['19'];
        $payable = $hasDiesel ? $calc['38'] : $calc['20'];

        return new ComputedReturn('vat201', $from->toDateString(), $to->toDateString(), $out, $output, $input, $payable, $issues, $rules->versions(), [], [
            'Fields 4, 4A and 11 are the VAT booked in the books. The VAT201 guide computes them as the field times 15/115 and lets the vendor edit them.',
            'The eFiling rounding rule for VAT201 fields (whole rand or cents) is not confirmed in our sources; figures here are in cents.',
        ]);
    }

    /** Cents of VAT payable according to the books alone (no adjustments), for the reconciliation. */
    public function fromBooksOnly(LedgerContext $ctx, CarbonImmutable $from, CarbonImmutable $to): ComputedReturn
    {
        return $this->build($ctx, $from, $to, []);
    }

    private function boxNote(string $code): ?string
    {
        return match ($code) {
            '1' => 'VAT-inclusive: the net value plus the VAT on the same entry.',
            '1A' => 'VAT-inclusive.',
            '4', '4A', '11' => 'The VAT booked. SARS lets the vendor edit this field.',
            '12' => 'Credit notes received, debit notes issued, recovered bad debts and similar.',
            '18' => 'Credit notes issued, debit notes received and similar.',
            '17' => 'Bad debts: invoice basis only.',
            '25', '29', '33', '37' => 'Optional. Only if you are registered for the diesel refund; the litres detail is not modelled.',
            '38' => 'Optional. The amount after the diesel refund.',
            default => null,
        };
    }

    /**
     * Group the tagged lines by (entry, tax code) and classify each line.
     *
     * @return list<array<string, mixed>>
     */
    private function groups(Collection $tagged, array &$issues): array
    {
        $groups = [];
        foreach ($tagged as $l) {
            $side = $this->side($l);
            if ($side === null) {
                continue;
            }

            $key = $l->entry_id.'|'.$l->code_id;
            $g = &$groups[$key];
            $g ??= [
                'entry_id' => (int) $l->entry_id, 'entry_number' => (int) $l->entry_number, 'date' => $l->entry_date, 'code' => $l->tax_code, 'kind' => $l->kind,
                'box_output' => $l->box_output, 'box_input' => $l->box_input, 'policy' => $l->deductible_policy, 'rate_ref' => $l->rate_ref,
                'supply' => 0, 'out_tax' => 0, 'purchase' => 0, 'in_tax' => 0, 'lines' => ['supply' => [], 'out_tax' => [], 'purchase' => [], 'in_tax' => []],
                'source_type' => $l->source_type,
            ];
            $signed = $this->signed($l, $side);
            $g[$side] += $signed;
            $g['lines'][$side][] = $this->contributor($l, $signed);
            unset($g);
        }

        return array_values($groups);
    }

    /** @return 'supply'|'out_tax'|'purchase'|'in_tax'|null */
    private function side(object $l): ?string
    {
        if ($l->system_key === 'vat_output') {
            return 'out_tax';
        }
        if ($l->system_key === 'vat_input') {
            return 'in_tax';
        }
        if (in_array($l->system_key, self::CONTROL_KEYS, true)) {
            return null;
        }

        return match ($l->gl_class) {
            'income' => 'supply',
            'expense', 'asset' => 'purchase',
            default => null,
        };
    }

    private function signed(object $l, string $side): int
    {
        $debit = (int) $l->debit_cents;
        $credit = (int) $l->credit_cents;

        return in_array($side, ['supply', 'out_tax'], true) ? $credit - $debit : $debit - $credit;
    }

    private function contributor(object $l, int $signed): array
    {
        return [
            'type' => 'line', 'id' => (int) $l->line_id, 'entry_id' => (int) $l->entry_id, 'entry_number' => (int) $l->entry_number,
            'date' => $l->entry_date, 'description' => trim(($l->gl_code.' '.$l->gl_name).($l->description ? ': '.$l->description : '')),
            'amount_cents' => $signed, 'note' => $l->source_type.' '.$l->source_id,
        ];
    }

    /**
     * Apply one (entry, code) group to the boxes.
     *
     * @param  array<string, mixed>  $g
     */
    private function evaluate(array $g, RuleSession $rules, int $rateBpAtEnd, callable $add, array &$issues): void
    {
        $firstLine = fn (string ...$sides) => collect($sides)->flatMap(fn ($s) => $g['lines'][$s])->first();
        $issue = function (string $code, string $severity, string $message, ?array $line, ?string $hint = null) use ($g, &$issues): void {
            $issues[] = [
                'code' => $code, 'severity' => $severity, 'journal_line_id' => $line['id'] ?? null, 'entry_id' => $g['entry_id'], 'entry_number' => $g['entry_number'],
                'date' => $g['date'], 'amount_cents' => $line['amount_cents'] ?? 0, 'message' => $message, 'hint' => $hint,
            ];
        };

        // ── output side ────────────────────────────────────────────────
        if ($g['supply'] !== 0 || $g['out_tax'] !== 0) {
            $this->outputSide($g, $rules, $add, $issue, $firstLine);
        }

        // ── input side ─────────────────────────────────────────────────
        if ($g['in_tax'] !== 0) {
            $line = $firstLine('in_tax');
            if ($g['in_tax'] > 0) {
                if (str_starts_with((string) $g['policy'], 'denied') || $g['box_input'] === null) {
                    $issue('denied_input_claimed', 'error', 'Input VAT is booked under a code that does not allow it to be claimed ('.$g['code'].'). It is left out of the return.', $line, 'Post the VAT-inclusive amount to the expense, or change the code if a claim is allowed.');
                } else {
                    $add($g['box_input'], $g['in_tax'], $g['lines']['in_tax']);
                    $this->checkAmount($g, $rules, $g['purchase'], $g['in_tax'], 'warning', $issue, $line);
                }
            } else {
                $add('12', -$g['in_tax'], $g['lines']['in_tax']);
            }
        } elseif ($g['purchase'] > 0 && $g['kind'] === 'standard' && ! str_starts_with((string) $g['policy'], 'denied') && $g['box_input'] !== null) {
            $issue('standard_purchase_without_vat', 'warning', 'A standard-rated purchase has no VAT line ('.$g['code'].'): no input VAT is claimed for it.', $firstLine('purchase'), 'Fine for a supplier who is not a VAT vendor or an invoice you cannot claim on. Otherwise post the VAT.');
        }
    }

    private function outputSide(array $g, RuleSession $rules, callable $add, callable $issue, callable $firstLine): void
    {
        $line = $firstLine('out_tax', 'supply');
        if ($g['box_output'] === null) {
            $issue('code_not_for_sales', 'error', 'Tax code '.$g['code'].' cannot be used on a sale: it has no output box.', $line, 'Change the code on the sale.');

            return;
        }

        $valueBox = $g['box_output'];
        $taxBox = $valueBox === '1A' ? '4A' : '4';
        $supply = $g['supply'];
        $tax = $g['out_tax'];

        if ($tax > 0) {
            $derived = false;
            if ($supply <= 0) {
                $supply = Money::divideRound($tax * 10_000, $rules->rate($g['rate_ref'] ?? 'ZA_VAT_STD', $g['date'])->rateBp);
                $derived = true;
                $issue('vat_without_base_line', 'warning', 'Output VAT is booked without a tagged sales line, so the value of the supply was worked back from the VAT.', $firstLine('out_tax'), 'Tag the sales line with the same tax code.');
            }

            if (in_array($g['kind'], ['zero', 'exempt', 'out_of_scope'], true)) {
                $issue('zero_rated_with_vat', 'error', 'VAT was charged on a line coded '.$g['code'].' ('.$g['kind'].'). A zero-rated or exempt supply carries no VAT.', $firstLine('out_tax'), 'Either the code or the VAT is wrong. The VAT is included in field 4 until you fix it.');
            }

            $contributors = $g['lines']['supply'];
            foreach ($g['lines']['out_tax'] as $c) {
                $contributors[] = $c;
            }
            if ($derived) {
                $contributors[] = ['type' => 'derived', 'id' => null, 'entry_id' => $g['entry_id'], 'entry_number' => $g['entry_number'], 'date' => $g['date'], 'description' => 'Value of the supply worked back from the VAT', 'amount_cents' => $supply, 'note' => 'Calculated'];
                // the VAT lines are already in the list above at their own amounts; the derived net completes the box
            }
            $add($valueBox, $supply + $tax, $contributors);
            $add($taxBox, $tax, $g['lines']['out_tax']);
            $this->checkAmount($g, $rules, $g['supply'], $tax, 'error', $issue, $firstLine('out_tax'));

            return;
        }

        if ($tax < 0) {
            // a credit note issued or a reversal in a later period: the VAT goes to field 18
            $add('18', -$tax, $g['lines']['out_tax']);

            return;
        }

        if ($supply > 0) {
            $add($valueBox, $supply, $g['lines']['supply']);
            if ($g['kind'] === 'standard') {
                $issue('standard_without_vat', 'error', 'A standard-rated sale has no VAT line ('.$g['code'].'). The VAT may have been left out.', $firstLine('supply'), 'Post the VAT to the output VAT account with the same tax code, or change the code if no VAT is due.');
            }
        }
    }

    /** Compare booked VAT with the rate in force on the entry's date; flag more than rounding. */
    private function checkAmount(array $g, RuleSession $rules, int $net, int $booked, string $severity, callable $issue, ?array $line): void
    {
        if ($g['kind'] !== 'standard' || $net <= 0 || $g['rate_ref'] === null) {
            return;
        }

        $bp = $rules->rate($g['rate_ref'], $g['date'])->rateBp;
        $expected = Money::mulDivRound($net, $bp, 10_000);
        $tolerance = max(1, count($g['lines']['supply']) + count($g['lines']['purchase']));

        if (abs($booked - $expected) > $tolerance) {
            $issue('vat_amount_mismatch', $severity, 'Booked VAT '.Money::format($booked).' differs from '.rtrim(rtrim(number_format($bp / 100, 2), '0'), '.').' percent of '.Money::format($net).' ('.Money::format($expected).') on '.$g['date'].' by more than rounding.', $line, 'Check the amount and the rate. A partial claim or an apportionment is a legitimate reason: accept it with a note.');
        }
    }

    /**
     * Leave out an entry and its reversal when both fall inside the period: they cancel.
     *
     * @param  Collection<int, object>  $tagged
     */
    private function withoutCancelledPairs(LedgerContext $ctx, Collection $tagged, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $reversals = $tagged->filter(fn ($l) => $l->reverses_entry_id !== null)->pluck('reverses_entry_id', 'entry_id');
        if ($reversals->isEmpty()) {
            return $tagged;
        }

        $dates = JournalEntry::query()->forContext($ctx)->whereIn('id', $reversals->values()->unique()->all())->get(['id', 'entry_date'])->mapWithKeys(fn ($e) => [$e->id => $e->entry_date->toDateString()]);
        $drop = [];
        foreach ($reversals as $reversalId => $originalId) {
            $date = $dates[$originalId] ?? null;
            if ($date !== null && $date >= $from->toDateString() && $date <= $to->toDateString()) {
                $drop[$reversalId] = true;
                $drop[$originalId] = true;
            }
        }

        return $tagged->reject(fn ($l) => isset($drop[$l->entry_id]));
    }
}
