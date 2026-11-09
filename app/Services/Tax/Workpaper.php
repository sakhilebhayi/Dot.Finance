<?php

namespace App\Services\Tax;

use App\Models\Tax\TaxReturn;
use App\Models\User;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/**
 * The working paper of a return: the view model for the print page and the CSV. Both always carry the banner, the period, the
 * basis, the filing channel and the rule versions used, because a number without them cannot be re-explained later.
 *
 * A prepared return is shown from its FROZEN figures; a draft is shown from a fresh computation and says it is a draft.
 */
final class Workpaper
{
    public function __construct(private readonly TaxReturnService $returns, private readonly FilingAdapter $filing) {}

    /** @return array<string, mixed> */
    public function build(LedgerContext $ctx, TaxReturn $return): array
    {
        $frozen = $return->figures !== null;
        $computed = $frozen ? null : $this->returns->compute($ctx, $return);
        $boxes = array_map(fn (array $b) => $b + ['basis' => $this->boxBasis($b)], $frozen ? $return->figures['boxes'] : $computed->boxes);

        return [
            'return' => $return,
            'kind' => $return->kind,
            'title' => $return->kindLabel().' working paper',
            'period' => $return->period_label,
            'period_start' => $return->period_start->toDateString(),
            'period_end' => $return->period_end->toDateString(),
            'basis' => $return->basis,
            'channel' => $return->channel,
            'revision' => $return->revision,
            'status' => $return->status,
            'status_label' => $return->statusLabel(),
            'banner' => $this->banner($return, $frozen),
            'frozen' => $frozen,
            'boxes' => $boxes,
            'output_tax_cents' => $frozen ? $return->output_tax_cents : $computed->outputTaxCents,
            'input_tax_cents' => $frozen ? $return->input_tax_cents : $computed->inputTaxCents,
            'payable_cents' => $frozen ? $return->payable_cents : $computed->payableCents,
            'rule_versions' => $frozen ? ($return->rule_versions ?? []) : $computed->ruleVersions,
            'reconciliation' => $frozen ? ($return->reconciliation ?? []) : ($computed->reconciliation ?? []),
            'adjustments' => $frozen ? ($return->figures['adjustments'] ?? []) : $return->activeAdjustments()->get()->map(fn ($a) => ['id' => $a->id, 'box' => $a->box_code, 'kind' => $a->kind, 'amount_cents' => $a->amount_cents, 'reason' => $a->reason])->all(),
            'notes' => $frozen ? ($return->figures['notes'] ?? []) : $computed->notes,
            'checklist' => $this->filing->instructions($return),
            'prepared_at' => $return->prepared_at,
            'figures_hash' => $return->figures_hash,
            'sars_reference' => $return->sars_reference,
            'submitted_on' => $return->submitted_on,
            'submitted_by' => $return->submitted_by_user_id ? User::query()->whereKey($return->submitted_by_user_id)->value('name') : null,
            'paid_cents' => $return->paid_cents,
            'paid_on' => $return->paid_on,
            'payment_reference' => $return->payment_reference,
            'generated_at' => CarbonImmutable::now(),
            'disclaimer' => TaxBasis::DISCLAIMER,
        ];
    }

    /**
     * How a field came to be (QA D-1306): every figure on the working paper carries one of the four basis tags. A field made of
     * lines from the books is Calculated; one made of a person's adjustments or a manually captured payroll summary is You entered;
     * a field with both says so. Calculated totals (13, 19, 20, ...) are Calculated.
     */
    public function boxBasis(array $box): string
    {
        if (($box['kind'] ?? '') === 'calc') {
            return TaxBasis::label(TaxBasis::CALCULATED);
        }

        $entered = false;
        $calculated = false;
        foreach ($box['contributors'] ?? [] as $c) {
            $manual = ($c['type'] ?? '') === 'adjustment' || (($c['type'] ?? '') === 'run' && ! str_contains((string) ($c['description'] ?? ''), 'imported from Dot.HR'));
            $entered = $entered || $manual;
            $calculated = $calculated || ! $manual;
        }

        return match (true) {
            $entered && $calculated => TaxBasis::label(TaxBasis::CALCULATED).' + '.TaxBasis::label(TaxBasis::ENTERED),
            $entered => TaxBasis::label(TaxBasis::ENTERED),
            default => TaxBasis::label(TaxBasis::CALCULATED),
        };
    }

    public function banner(TaxReturn $return, bool $frozen): string
    {
        return match (true) {
            $return->isSubmitted() => 'RECORDED AS SUBMITTED BY A PERSON ('.($return->submitted_by_user_id ? User::query()->whereKey($return->submitted_by_user_id)->value('name').', ' : '').'SARS reference '.$return->sars_reference.', '.$return->submitted_on->format('j F Y').'). Dot.Finance did not submit this to SARS.',
            $frozen => TaxBasis::NOT_SUBMITTED,
            default => 'DRAFT, NOT PREPARED, NOT SUBMITTED. These figures are recomputed from the books each time and can still change. Dot.Finance does not submit anything to SARS.',
        };
    }

    public function csv(array $paper): string
    {
        $rows = [
            [$paper['banner']],
            [$paper['title'], $paper['period'], 'Revision '.$paper['revision']],
            ['Period', $paper['period_start'], $paper['period_end']],
            ['Basis', $paper['basis'] ?? 'not applicable'],
            ['Filing channel', $paper['channel'] ?? 'not applicable'],
            ['Status', $paper['status_label']],
            ['Generated', $paper['generated_at']->toDateTimeString()],
            [],
            ['Field', 'Description', 'Amount', 'Kind', 'Basis', 'Note'],
        ];
        foreach ($paper['boxes'] as $b) {
            $rows[] = [$b['code'], $b['label'], CsvSafe::money((int) $b['amount_cents']), $b['kind'], $b['basis'], $b['note'] ?? ''];
        }
        $rows[] = [];
        $rows[] = ['Output tax', '', CsvSafe::money((int) $paper['output_tax_cents']), 'calc', TaxBasis::label(TaxBasis::CALCULATED)];
        $rows[] = ['Input tax', '', CsvSafe::money((int) $paper['input_tax_cents']), 'calc', TaxBasis::label(TaxBasis::CALCULATED)];
        $rows[] = [(int) $paper['payable_cents'] >= 0 ? 'Payable to SARS' : 'Refundable from SARS', '', CsvSafe::money((int) $paper['payable_cents']), 'calc', TaxBasis::label(TaxBasis::CALCULATED)];

        if ($paper['adjustments'] !== []) {
            $rows[] = [];
            $rows[] = ['Adjustments entered by a person', 'Field', 'Amount', 'Kind', 'Reason'];
            foreach ($paper['adjustments'] as $a) {
                $rows[] = ['#'.$a['id'], $a['box'], CsvSafe::money((int) $a['amount_cents']), $a['kind'], $a['reason']];
            }
        }

        $rows[] = [];
        $rows[] = ['Drill-down: the lines behind each field'];
        $rows[] = ['Field', 'Type', 'Entry', 'Date', 'Description', 'Amount'];
        foreach ($paper['boxes'] as $b) {
            foreach ($b['contributors'] as $c) {
                $rows[] = [$b['code'], $c['type'], $c['entry_number'] ? 'JE-'.str_pad((string) $c['entry_number'], 6, '0', STR_PAD_LEFT) : '', $c['date'] ?? '', $c['description'], CsvSafe::money((int) $c['amount_cents'])];
            }
        }

        if (($paper['reconciliation']['gl_net_cents'] ?? null) !== null) {
            $r = $paper['reconciliation'];
            $rows[] = [];
            $rows[] = ['Reconciliation to the VAT accounts'];
            $rows[] = ['Net VAT in the books', '', CsvSafe::money((int) $r['gl_net_cents'])];
            $rows[] = ['Return from the books (field 20 before adjustments)', '', CsvSafe::money((int) $r['return_from_books_cents'])];
            $rows[] = ['Difference', '', CsvSafe::money((int) $r['difference_cents'])];
            foreach ($r['explained'] ?? [] as $e) {
                $rows[] = ['Explained: '.$e['label'], '', CsvSafe::money((int) $e['cents'])];
            }
            $rows[] = ['Unexplained', '', CsvSafe::money((int) $r['unexplained_cents'])];
        }

        $rows[] = [];
        $rows[] = ['Rule versions used'];
        $rows[] = ['Version', 'Rule', 'Confidence', 'Legal status', 'Valid from', 'Source'];
        foreach ($paper['rule_versions'] as $v) {
            $rows[] = [$v['version'], $v['key'], $v['confidence'], $v['legal_status'], $v['valid_from'], $v['source_url']];
        }
        $rows[] = [];
        $rows[] = [$paper['disclaimer']];

        return CsvSafe::render($rows);
    }
}
