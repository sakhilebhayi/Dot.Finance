<?php

namespace App\Services\Intelligence\Handlers;

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

        return new Answer(
            Intent::WhoOwesMe,
            Answer::ANSWERED,
            $this->plural(count($owed), 'customer owes', 'customers owe').' you '.$this->money($total, $currency).'; the most is '.$this->money($top['owed_cents'], $currency)." from {$top['party']}.",
            [['label' => 'Total owed to you', 'value' => $this->money($total, $currency)]],
            ['Customer', 'Owes you'],
            $rows,
            $link,
            notes: ['Worked out from the receivables account of the general ledger. It does not say which invoices are overdue.'],
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
