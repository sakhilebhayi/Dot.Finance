<?php

namespace App\Services\Purchases;

use App\Contracts\Bank\OpenDocument;
use App\Contracts\Bank\OpenDocumentSource;
use App\Models\Bank\BankStatementLine;
use App\Models\Purchases\Bill;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Bank\DocumentNumbers;
use App\Support\Ledger\LedgerContext;
use App\Support\Shared\Exceptions\AllocationRejected;
use Illuminate\Support\Str;

/**
 * Purchases' side of the bank workspace's "settle a bill" action (money out). It offers the ledger's open supplier bills and
 * expense claims a statement line might be paying, with the reason, and settles one through the real supplier-payment service:
 * the payment recorded and allocated, Dr payables / Cr bank posted, and the cash-ledger row the bank module then matches.
 *
 * A bill is offered when (best first) the narrative carries its number or the supplier's own invoice number, the line is exactly
 * what it still owes, or the narrative names the supplier and the bill owes at least the line. Same currency only.
 */
final class PurchaseOpenDocuments implements OpenDocumentSource
{
    private const MAX_PER_LINE = 5;

    public function __construct(private readonly ApLedger $ledger, private readonly SupplierPayments $payments) {}

    public function direction(): string
    {
        return 'out';
    }

    public function candidates(LedgerContext $context, array $lines): array
    {
        $items = $this->ledger->openItems($context)->filter(fn (array $i) => in_array($i['type'], ['bill', 'expense_claim'], true) && $i['open'] > 0)->values();
        if ($items->isEmpty()) {
            return [];
        }

        $references = LedgerContext::run($context, fn () => Bill::query()->whereIn('id', $items->where('type', 'bill')->pluck('id'))->pluck('supplier_reference', 'id'));

        $out = [];
        foreach ($lines as $line) {
            if ($line['cents'] >= 0) {
                continue;
            }
            $cents = -$line['cents'];
            $ranked = [];

            foreach ($items as $item) {
                if (strtoupper($item['currency']) !== strtoupper($line['currency'])) {
                    continue;
                }

                $supplierRef = $item['type'] === 'bill' ? (string) ($references[$item['id']] ?? '') : '';
                $name = mb_strtolower((string) $item['party']);
                $why = null;
                $rank = null;

                if (DocumentNumbers::mentioned($line['text'], (string) $item['number'], $line['docs']) || DocumentNumbers::mentioned($line['text'], $supplierRef, $line['docs'])) {
                    [$rank, $why] = [0, 'the narrative mentions '.$item['number'].(filled($references[$item['id']] ?? null) ? ' ('.$references[$item['id']].')' : '')];
                } elseif ($item['open'] === $cents) {
                    [$rank, $why] = [1, 'the amount is exactly what '.$item['number'].' still owes'];
                } elseif (mb_strlen($name) >= 4 && str_contains($line['text'], $name) && $item['open'] >= $cents) {
                    [$rank, $why] = [2, 'the narrative names '.$item['party'].' and '.$item['number'].' still owes at least this much'];
                }

                if ($rank !== null) {
                    $ranked[] = [$rank, (string) $item['due_date'], new OpenDocument($item['type'], (int) $item['id'], (string) $item['number'], (string) $item['party'], (int) $item['open'], (string) $item['currency'], $item['due_date'], 'out', $why)];
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
            if (! in_array($document->type, ['bill', 'expense_claim'], true)) {
                throw new AllocationRejected('That is not a bill or an expense claim.');
            }

            $item = $this->ledger->openItems($context)->first(fn (array $i) => $i['type'] === $document->type && $i['id'] === $document->id);
            if ($item === null) {
                throw new AllocationRejected('That bill is no longer open in this ledger.');
            }

            $payment = $this->payments->pay($context, $user, [
                'party_id' => (int) $item['party_id'],
                'cash_account_id' => (int) $line->account_id,
                'paid_on' => $line->posted_on->toDateString(),
                'method' => 'eft',
                'reference' => Str::limit((string) ($line->reference ?: $line->description), 100, ''),
                'idempotency_key' => $idempotencyKey,
                'notes' => 'Settled from bank statement line '.$line->id.' ('.$document->why.').',
                'allocations' => [['type' => $document->type, 'id' => $document->id, 'amount_cents' => $amountCents]],
            ]);

            return Transaction::query()->forContext($context)->findOrFail((int) $payment->transaction_id);
        });
    }
}
