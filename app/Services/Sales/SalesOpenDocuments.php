<?php

namespace App\Services\Sales;

use App\Contracts\Bank\OpenDocument;
use App\Contracts\Bank\OpenDocumentSource;
use App\Models\Bank\BankStatementLine;
use App\Models\Sales\SalesDocument;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Bank\DocumentNumbers;
use App\Support\Ledger\LedgerContext;
use App\Support\Shared\Exceptions\AllocationRejected;

/**
 * Sales' side of the bank workspace's "settle an invoice" action (money in). It offers the ledger's open invoices a
 * statement line might be paying, with the reason, and settles one through the real receipt service: a recorded payment
 * allocated to the invoice, the receivables journal, and the cash-ledger row the bank module then matches to the line.
 *
 * An invoice is offered when (best first) the narrative carries its number, the line is exactly what the invoice still owes,
 * or the narrative names the customer and the invoice still owes at least the line. Same currency only, because a payment
 * cannot settle an invoice in another currency.
 */
final class SalesOpenDocuments implements OpenDocumentSource
{
    private const MAX_PER_LINE = 5;

    public function __construct(private readonly ReceivablesLedger $receivables, private readonly ReceiptService $receipts) {}

    public function direction(): string
    {
        return 'in';
    }

    public function candidates(LedgerContext $context, array $lines): array
    {
        $open = $this->receivables->openItems($context)->filter(fn (array $row) => $row['open_cents'] > 0);
        if ($open->isEmpty()) {
            return [];
        }

        $out = [];
        foreach ($lines as $line) {
            $ranked = [];
            foreach ($open as $row) {
                /** @var SalesDocument $doc */
                $doc = $row['document'];
                if ($line['cents'] <= 0 || strtoupper($row['currency']) !== strtoupper($line['currency'])) {
                    continue;
                }

                $number = $doc->number ?? '';
                $name = mb_strtolower((string) ($doc->party?->name ?? ''));
                $why = null;
                $rank = null;

                if (DocumentNumbers::mentioned($line['text'], $number, $line['docs'])) {
                    [$rank, $why] = [0, "the narrative mentions {$number}"];
                } elseif ($row['open_cents'] === $line['cents']) {
                    [$rank, $why] = [1, "the amount is exactly what {$number} still owes"];
                } elseif (mb_strlen($name) >= 4 && str_contains($line['text'], $name) && $row['open_cents'] >= $line['cents']) {
                    [$rank, $why] = [2, 'the narrative names '.$doc->party->name.' and '.$number.' still owes at least this much'];
                }

                if ($rank !== null) {
                    $ranked[] = [$rank, (string) $doc->due_date?->toDateString(), $this->document($row, $why)];
                }
            }

            if ($ranked !== []) {
                usort($ranked, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
                $out[$line['id']] = array_map(fn ($r) => $r[2], array_slice($ranked, 0, self::MAX_PER_LINE));
            }
        }

        return $out;
    }

    public function settle(LedgerContext $context, User $user, BankStatementLine $line, OpenDocument $document, int $amountCents, string $idempotencyKey): Transaction
    {
        return LedgerContext::run($context, function () use ($context, $user, $line, $document, $amountCents, $idempotencyKey) {
            $invoice = SalesDocument::query()->forContext($context)->find($document->id);
            if ($invoice === null) {
                throw new AllocationRejected('That invoice is not in this ledger.');
            }

            $result = $this->receipts->receive($context, $user, [
                'party_id' => (int) $invoice->party_id,
                'cash_account_id' => (int) $line->account_id,
                'amount_cents' => $amountCents,
                'paid_on' => $line->posted_on->toDateString(),
                'method' => 'eft',
                'reference' => mb_substr((string) ($line->reference ?: $line->description), 0, 100) ?: null,
                'idempotency_key' => $idempotencyKey,
                'notes' => 'Settled from bank statement line '.$line->id.' ('.$document->why.').',
                'allocations' => [['document_id' => (int) $invoice->id, 'amount_cents' => $amountCents]],
            ]);

            $payment = $result['payment'];

            return Transaction::query()->forContext($context)->findOrFail((int) $payment->transaction_id);
        });
    }

    /** @param array<string, mixed> $row */
    private function document(array $row, string $why): OpenDocument
    {
        /** @var SalesDocument $doc */
        $doc = $row['document'];

        return new OpenDocument(
            $doc->getMorphClass(), (int) $doc->id, (string) $doc->number, (string) ($doc->party?->name ?? 'Customer'), (int) $row['open_cents'], (string) $row['currency'],
            $doc->due_date?->toDateString(), 'in', $why,
        );
    }
}
