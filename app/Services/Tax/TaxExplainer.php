<?php

namespace App\Services\Tax;

use App\Models\Accounting\JournalEntry;
use App\Models\Tax\TaxDeadline;
use App\Models\Tax\TaxProfile;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Rule-based, grounded explanations. Each one is assembled from rows that exist (journal lines, the rule book, the return) and
 * carries a basis tag; there is no model in the loop and nothing is invented. Where the answer involves interpretation it ends with
 * the "information, not professional tax advice" footer.
 *
 *  - transaction(): the VAT effect of one journal entry (what code, which box, claimable or not, why)
 *  - change():      why a box changed, by the entries that moved it, between two dates the figure was looked at
 *                   (what was posted in between) or between two periods
 *  - missing():     what stands between this deadline and being done
 *  - giveAccountant(): what to hand your accountant, as a list that matches the accountant pack
 */
final class TaxExplainer
{
    public function __construct(
        private readonly GlTaxLines $lines,
        private readonly Vat201Builder $vat,
        private readonly TaxRules $rules,
        private readonly MissingRecords $missing,
        private readonly TaxChecklists $checklists,
    ) {}

    /**
     * @return array{found: bool, title: string, paragraphs: list<string>, rows: list<array<string, mixed>>, basis: string, footer: string}
     */
    public function transaction(LedgerContext $ctx, int $entryId): array
    {
        $entry = JournalEntry::query()->forContext($ctx)->find($entryId);
        if ($entry === null) {
            return $this->none('There is no such entry in this ledger.');
        }

        $tagged = $this->lines->tagged($ctx, $entry->entry_date->toDateString(), $entry->entry_date->toDateString())->where('entry_id', $entry->id);
        $rules = $this->rules->session($ctx);
        $paragraphs = [];
        $rows = [];

        if ($tagged->isEmpty()) {
            return [
                'found' => true, 'title' => $entry->reference().': '.$entry->memo,
                'paragraphs' => ['No line of this entry carries a tax code, so none of it is reported in a VAT201 box. If it should carry VAT, the entry is append-only: reverse it and post it again with the tax code.'],
                'rows' => [], 'basis' => TaxBasis::CALCULATED, 'footer' => TaxBasis::DISCLAIMER,
            ];
        }

        foreach ($tagged->groupBy('tax_code') as $code => $group) {
            $first = $group->first();
            $vatAccounts = $group->filter(fn ($l) => in_array($l->system_key, ['vat_output', 'vat_input'], true));
            $base = $group->reject(fn ($l) => in_array($l->system_key, ['vat_output', 'vat_input'], true));
            $isSale = $base->contains(fn ($l) => $l->gl_class === 'income') || $vatAccounts->contains(fn ($l) => $l->system_key === 'vat_output');
            $vatCents = (int) $vatAccounts->sum(fn ($l) => $l->system_key === 'vat_output' ? $l->credit_cents - $l->debit_cents : $l->debit_cents - $l->credit_cents);
            $netCents = (int) $base->sum(fn ($l) => $isSale ? $l->credit_cents - $l->debit_cents : $l->debit_cents - $l->credit_cents);
            $box = $isSale ? $first->box_output : $first->box_input;

            $rate = null;
            if ($first->rate_ref) {
                $rate = $rules->rate($first->rate_ref, $entry->entry_date)->rateBp;
            }

            $text = "{$code}: ".($isSale ? 'a sale' : 'a purchase').' of '.Money::format(abs($netCents), 'ZAR').' before VAT'
                .($rate !== null ? ', with VAT at '.rtrim(rtrim(number_format($rate / 100, 2), '0'), '.').' percent on '.$entry->entry_date->toDateString().' ('.Money::format(abs($vatCents), 'ZAR').' booked)' : ', with no VAT charged')
                .'.';
            $paragraphs[] = $text;

            if ($isSale) {
                $paragraphs[] = $vatCents >= 0
                    ? ($box ? "It is reported in field {$box}".($vatCents > 0 ? ' and its VAT in field '.($box === '1A' ? '4A' : '4') : '').' of the VAT201.' : 'This code has no output box, so it is left out of the return.')
                    : 'The VAT is negative (a credit note or reversal): it is reported as a credit-note adjustment in field 18.';
            } else {
                if (str_starts_with((string) $first->deductible_policy, 'denied') || $box === null) {
                    $paragraphs[] = 'Input VAT on this kind of purchase is not claimable ('.str_replace('_', ' ', (string) $first->deductible_policy).'): no box, so it adds nothing to the return and the cost stays in the expense.';
                } elseif ($vatCents > 0) {
                    $paragraphs[] = "The input VAT is claimed in field {$box}, provided you hold a valid tax invoice from a VAT vendor.";
                } elseif ($vatCents < 0) {
                    $paragraphs[] = 'The VAT is negative (a supplier credit note or reversal): it is reported in field 12.';
                }
            }

            $rows[] = ['code' => $code, 'kind' => $first->kind, 'side' => $isSale ? 'sale' : 'purchase', 'net_cents' => $netCents, 'vat_cents' => $vatCents, 'rate_bp' => $rate, 'box' => $box, 'policy' => $first->deductible_policy];
        }

        $period = $entry->period;
        $paragraphs[] = 'Entry '.$entry->reference().' is dated '.$entry->entry_date->toDateString().($period ? ", so it falls in the period {$period->start_date->toDateString()} to {$period->end_date->toDateString()}" : '').'. The VAT period of a return is decided by this date, which is the invoice date on the invoice basis.';

        return ['found' => true, 'title' => $entry->reference().': '.$entry->memo, 'paragraphs' => $paragraphs, 'rows' => $rows, 'basis' => TaxBasis::CALCULATED, 'footer' => TaxBasis::DISCLAIMER];
    }

    /**
     * Why a figure moved between two looks at the same period (what was posted in between) or between two periods.
     *
     * @return array{found: bool, title: string, paragraphs: list<string>, rows: list<array<string, mixed>>, new_lines: list<array<string, mixed>>, basis: string, footer: string}
     */
    public function change(LedgerContext $ctx, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $postedBefore, CarbonImmutable $postedAfter): array
    {
        $before = $this->vat->build($ctx, $from, $to, [], $postedBefore);
        $after = $this->vat->build($ctx, $from, $to, [], $postedAfter);
        $rows = [];

        foreach ($after->boxes as $box) {
            $b = $before->amount($box['code']);
            if ($b !== $box['amount_cents']) {
                $rows[] = ['box' => $box['code'], 'label' => $box['label'], 'before_cents' => $b, 'after_cents' => $box['amount_cents'], 'delta_cents' => $box['amount_cents'] - $b];
            }
        }

        $known = [];
        foreach ($before->boxes as $box) {
            foreach ($box['contributors'] as $c) {
                if ($c['type'] === 'line') {
                    $known[$c['id']] = true;
                }
            }
        }
        $new = [];
        foreach ($after->boxes as $box) {
            foreach ($box['contributors'] as $c) {
                if ($c['type'] === 'line' && ! isset($known[$c['id']])) {
                    $new[$c['id'].'|'.$box['code']] = $c + ['box' => $box['code']];
                }
            }
        }

        $paragraphs = [];
        if ($rows === []) {
            $paragraphs[] = 'Nothing in the VAT201 changed between '.$postedBefore->format('j M Y H:i').' and '.$postedAfter->format('j M Y H:i').' for the period '.$from->toDateString().' to '.$to->toDateString().'.';
        } else {
            $payable = $after->payableCents - $before->payableCents;
            $paragraphs[] = 'VAT payable changed by '.Money::format($payable, 'ZAR', true).' (from '.Money::format($before->payableCents, 'ZAR').' to '.Money::format($after->payableCents, 'ZAR').') because entries were posted between '.$postedBefore->format('j M Y H:i').' and '.$postedAfter->format('j M Y H:i').'.';
            $paragraphs[] = count($new).' journal line'.(count($new) === 1 ? '' : 's').' reached the return in that time; they are listed below with the box each one moved.';
        }

        return ['found' => true, 'title' => 'What changed in the VAT201 for '.$from->format('j M Y').' to '.$to->format('j M Y'), 'paragraphs' => $paragraphs, 'rows' => $rows, 'new_lines' => array_values($new), 'basis' => TaxBasis::CALCULATED, 'footer' => TaxBasis::DISCLAIMER];
    }

    /** Compare two periods box by box, with the accounts and tax codes behind the difference. @return array<string, mixed> */
    public function comparePeriods(LedgerContext $ctx, CarbonImmutable $aFrom, CarbonImmutable $aTo, CarbonImmutable $bFrom, CarbonImmutable $bTo): array
    {
        $a = $this->vat->build($ctx, $aFrom, $aTo, []);
        $b = $this->vat->build($ctx, $bFrom, $bTo, []);
        $rows = [];
        foreach ($b->boxes as $box) {
            $before = $a->amount($box['code']);
            if ($before !== $box['amount_cents']) {
                $drivers = collect($box['contributors'])->where('type', 'line')->sortByDesc(fn ($c) => abs($c['amount_cents']))->take(3)->map(fn ($c) => $c['description'].' '.Money::format($c['amount_cents'], 'ZAR'))->values()->all();
                $rows[] = ['box' => $box['code'], 'label' => $box['label'], 'a_cents' => $before, 'b_cents' => $box['amount_cents'], 'delta_cents' => $box['amount_cents'] - $before, 'drivers' => $drivers];
            }
        }

        return ['rows' => $rows, 'a_payable_cents' => $a->payableCents, 'b_payable_cents' => $b->payableCents, 'basis' => TaxBasis::CALCULATED, 'footer' => TaxBasis::DISCLAIMER];
    }

    /** @return array{items: list<array<string, mixed>>, checklist: list<array<string, mixed>>, basis: string} */
    public function missing(LedgerContext $ctx, TaxDeadline $deadline, ?TaxProfile $profile): array
    {
        return [
            'items' => $this->missing->forDeadline($ctx, $deadline, $profile),
            'checklist' => array_values(array_filter($this->checklists->for($ctx, $deadline, $profile), fn ($i) => ! $i['done'])),
            'basis' => TaxBasis::CALCULATED,
        ];
    }

    /** What to give your accountant, in the order they will ask for it. @return list<array{item: string, why: string, where: string}> */
    public function giveAccountant(): array
    {
        return [
            ['item' => 'Trial balance at year end and at each VAT period end', 'why' => 'It proves the books balance and is the start of every tax computation.', 'where' => 'In the accountant pack (trial_balance.csv)'],
            ['item' => 'General ledger for the year', 'why' => 'Every posting, with its entry number, date and source.', 'where' => 'In the accountant pack (general_ledger.csv)'],
            ['item' => 'VAT201 working papers for each period, with the adjustments and their reasons', 'why' => 'They show each field, the lines behind it and the rule versions used.', 'where' => 'In the accountant pack (vat201 folder)'],
            ['item' => 'Income tax adjustments and the review notes', 'why' => 'The add-backs and deductions you entered, each with a reason.', 'where' => 'In the accountant pack (income_tax_adjustments.csv)'],
            ['item' => 'The income tax and provisional tax estimates', 'why' => 'Estimates, labelled as such; the accountant will replace them with a return.', 'where' => 'In the accountant pack (income_tax_estimates.csv)'],
            ['item' => 'Payroll tax summaries (totals) and EMP201 working papers', 'why' => 'PAYE, UIF, SDL and ETI by month. Per-employee detail is only included for people allowed to see payroll detail.', 'where' => 'In the accountant pack (payroll_summaries.csv)'],
            ['item' => 'Index of the tax documents you hold, and the SARS correspondence log', 'why' => 'Shows what exists and where; the files themselves stay in the vault.', 'where' => 'In the accountant pack (document_index.csv, correspondence.csv)'],
            ['item' => 'Assessments, SARS statements of account and prior-year returns', 'why' => 'Dot.Finance cannot see your SARS profile: these come from eFiling.', 'where' => 'From SARS eFiling'],
        ];
    }

    private function none(string $message): array
    {
        return ['found' => false, 'title' => 'Nothing to explain', 'paragraphs' => [$message], 'rows' => [], 'basis' => TaxBasis::CALCULATED, 'footer' => TaxBasis::DISCLAIMER];
    }
}
