<?php

namespace App\Services\Intelligence\Handlers;

use App\Contracts\Intelligence\DescribesReceivables;
use App\Contracts\Intelligence\ReceivablesSource;
use App\Models\User;
use App\Services\Intelligence\Answer;
use App\Services\Intelligence\Intent;
use App\Services\Intelligence\RecognisedIntent;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/** "Who owes me money?" and "Which invoices are overdue?" From whatever receivables source this ledger has. */
final class ReceivablesHandler extends Handler
{
    public function __construct(private readonly ReceivablesSource $source) {}

    public function intents(): array
    {
        return [Intent::WhoOwesMe, Intent::OverdueInvoices];
    }

    public function handle(RecognisedIntent $question, LedgerContext $context, ?User $user = null): Answer
    {
        return $question->intent === Intent::WhoOwesMe ? $this->owed($context) : $this->overdue($context);
    }

    private function owed(LedgerContext $context): Answer
    {
        $owed = $this->source->owedByCustomers($context);
        $link = $this->sources(['Customers', fx_route(['parties.customers.index', 'customers.index', 'sales.customers.index', 'parties.index'])]);

        if ($owed === null) {
            return Answer::noData(Intent::WhoOwesMe, "I don't have that information: nothing has been recorded against customers yet. Receivables appear once invoices are issued, or journals are posted to the receivables account, with accounting switched on.", $link);
        }

        if ($owed === []) {
            return new Answer(Intent::WhoOwesMe, Answer::ANSWERED, 'No customer owes you anything according to the receivables account.', sources: $link);
        }

        $currency = $owed[0]['currency'];
        $total = array_sum(array_column($owed, 'owed_cents'));
        $rows = [];
        foreach (array_slice($owed, 0, 8) as $row) {
            $rows[] = ['cells' => [$row['party'], $this->money($row['owed_cents'], $row['currency'])], 'href' => $row['href']];
        }

        $top = $owed[0];
        $facts = [['label' => 'Total owed to you', 'value' => $this->money($total, $currency)]];
        $position = $this->source instanceof DescribesReceivables ? $this->source->position($context) : null;

        if ($position === null) {
            // nothing but the general ledger to go on: say so
            $notes = ['Worked out from the receivables account of the general ledger. It does not say which invoices are overdue.'];
        } else {
            // the same figure the Invoices, Customers and Receivables pages show; the sentence and the table must add up to it
            $total = $position['total_cents'];
            $facts = [['label' => 'Owed to you', 'value' => $this->money($total, $currency)]];
            $notes = ['Worked out from '.$position['source'].', the same figure as the Invoices and Receivables pages.'];
            $rowsTotal = array_sum(array_column($owed, 'owed_cents'));
            if ($rowsTotal !== $total) {
                $facts[] = ['label' => 'Customers who owe you', 'value' => $this->money($rowsTotal, $currency)];
                $facts[] = ['label' => 'Held as customer credit', 'value' => $this->money($rowsTotal - $total, $currency)];
                $notes[] = 'Customers who have paid ahead or hold a credit note are netted off: '.$this->money($rowsTotal - $total, $currency).' of credit is held for them.';
            }
            if (! $position['agrees']) {
                $notes[] = 'The receivables account of the general ledger stands at '.$this->money($position['books_cents'], $currency).', which is '.$this->money(abs($position['gap_cents']), $currency).($position['gap_cents'] > 0 ? ' more' : ' less').' than that. '
                    .($position['reconciling'] ? 'Reconciling items: '.implode('; ', $position['reconciling']).'. ' : '').'The Receivables page lists every one.';
            }
        }

        return new Answer(
            Intent::WhoOwesMe,
            Answer::ANSWERED,
            $this->plural(count($owed), 'customer owes', 'customers owe').' you '.$this->money($total, $currency).'; the most is '.$this->money($top['owed_cents'], $currency)." from {$top['party']}.",
            $facts,
            ['Customer', 'Owes you'],
            $rows,
            $position === null ? $link : $this->sources(['Receivables', fx_route('sales.receivables.index')], ['Customers', fx_route('sales.customers.index')]),
            notes: $notes,
        );
    }

    private function overdue(LedgerContext $context): Answer
    {
        $overdue = $this->source->overdueInvoices($context, CarbonImmutable::now()->startOfDay());
        $link = $this->sources(['Invoices', fx_route(['sales.invoices.index', 'invoices.index'])]);

        if ($overdue === null) {
            return Answer::noData(Intent::OverdueInvoices, "I don't have that information: this ledger has no invoices with due dates yet, so I cannot tell which are overdue.", $link);
        }

        if ($overdue === []) {
            return new Answer(Intent::OverdueInvoices, Answer::ANSWERED, 'No invoice is overdue.', sources: $link);
        }

        $currency = $overdue[0]['currency'];
        $total = array_sum(array_column($overdue, 'open_cents'));
        usort($overdue, fn ($a, $b) => $b['days_overdue'] <=> $a['days_overdue']);

        $rows = [];
        foreach (array_slice($overdue, 0, 8) as $row) {
            $rows[] = [
                'cells' => [$row['number'], $row['party'], 'due '.$row['due_on'], $this->plural($row['days_overdue'], 'day', 'days').' late', $this->money($row['open_cents'], $row['currency'])],
                'href' => $row['href'],
            ];
        }

        $oldest = $overdue[0];

        return new Answer(
            Intent::OverdueInvoices,
            Answer::ANSWERED,
            $this->plural(count($overdue), 'invoice is', 'invoices are').' overdue, '.$this->money($total, $currency)." in all; the oldest is {$oldest['number']} from {$oldest['party']}, ".$this->plural($oldest['days_overdue'], 'day', 'days').' late.',
            [['label' => 'Overdue in total', 'value' => $this->money($total, $currency), 'tone' => 'variance']],
            ['Invoice', 'Customer', 'Due', 'Late by', 'Open'],
            $rows,
            $link,
        );
    }
}
