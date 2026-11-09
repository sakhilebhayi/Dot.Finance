<?php

namespace App\Services\Intelligence\Handlers;

use App\Contracts\Intelligence\TaxPositionSource;
use App\Models\User;
use App\Services\Intelligence\Answer;
use App\Services\Intelligence\Intent;
use App\Services\Intelligence\RecognisedIntent;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/** "What is my VAT liability so far?" and "What tax do I need to prepare for?" From the tax source this ledger has. */
final class TaxHandler extends Handler
{
    public function __construct(private readonly TaxPositionSource $tax) {}

    public function intents(): array
    {
        return [Intent::VatLiability, Intent::TaxToPrepare];
    }

    public function handle(RecognisedIntent $question, LedgerContext $context, ?User $user = null): Answer
    {
        return $question->intent === Intent::VatLiability ? $this->vat($question, $context) : $this->deadlines($context);
    }

    private function vat(RecognisedIntent $q, LedgerContext $context): Answer
    {
        $now = CarbonImmutable::now()->startOfDay();
        // With no period named, "so far" means this year so far: the Tax module's own binding knows the real VAT period.
        $from = $q->period->explicit ? CarbonImmutable::parse($q->period->fromDate()) : $now->startOfYear();
        $to = $q->period->explicit ? CarbonImmutable::parse($q->period->toDate()) : $now;
        $label = $q->period->explicit ? $q->period->label : 'this year so far';

        $link = $this->sources(['VAT', fx_route(['tax.vat.index', 'vat.index'])]);
        $position = $this->tax->vatPosition($context, $from, $to);

        if ($position === null) {
            return Answer::noData(Intent::VatLiability, "I don't have that information: no VAT has been recorded in the general ledger for {$label}. VAT appears once invoices and bills carry a tax code, with accounting switched on.", $link);
        }

        $net = $position['net_cents'];
        $c = $position['currency'];

        return new Answer(
            Intent::VatLiability,
            Answer::ANSWERED,
            $net >= 0
                ? 'For '.$label.', you have collected '.$this->money($position['output_cents'], $c).' of VAT and may claim '.$this->money($position['input_cents'], $c).', so '.$this->money($net, $c).' is owed to SARS on these figures.'
                : 'For '.$label.', you have collected '.$this->money($position['output_cents'], $c).' of VAT and may claim '.$this->money($position['input_cents'], $c).', so '.$this->money(abs($net), $c).' would be refundable on these figures.',
            [
                ['label' => 'VAT collected on sales (output)', 'value' => $this->money($position['output_cents'], $c)],
                ['label' => 'VAT claimable on purchases (input)', 'value' => $this->money($position['input_cents'], $c)],
                ['label' => $net >= 0 ? 'Owed to SARS' : 'Refundable', 'value' => $this->money(abs($net), $c)],
            ],
            sources: array_merge($this->sources(['VAT accounts in the general ledger', $position['href']]), $link),
            notes: [$position['basis_note'], $q->period->explicit ? '' : 'No period was named. Your VAT filing period may be different.'],
        );
    }

    private function deadlines(LedgerContext $context): Answer
    {
        $from = CarbonImmutable::now()->startOfDay();
        $deadlines = $this->tax->deadlines($context, $from, $from->addDays(90));
        $link = $this->sources(['Tax overview', fx_route('tax.index')]);

        if ($deadlines === null) {
            return Answer::noData(Intent::TaxToPrepare, "I don't have that information: this ledger has no tax profile or calendar yet, so I cannot say which returns apply to you. The Tax screens set that up from your business type and VAT registration.", $link);
        }

        if ($deadlines === []) {
            return new Answer(Intent::TaxToPrepare, Answer::ANSWERED, 'No tax deadline falls in the next 90 days according to your tax calendar.', sources: $link);
        }

        $rows = [];
        foreach (array_slice($deadlines, 0, 8) as $d) {
            $rows[] = ['cells' => [$d['due_on'], $d['label'], $d['note'] ?? ''], 'href' => $d['href']];
        }

        $first = $deadlines[0];

        return new Answer(
            Intent::TaxToPrepare,
            Answer::ANSWERED,
            $this->plural(count($deadlines), 'tax deadline falls', 'tax deadlines fall').' in the next 90 days; the first is '.$first['label'].' on '.$first['due_on'].'.',
            columns: ['Date', 'What', 'Note'],
            rows: $rows,
            sources: $link,
            notes: ['Dates come from your tax calendar. Check them with a registered tax practitioner or SARS before you rely on them.'],
        );
    }
}
