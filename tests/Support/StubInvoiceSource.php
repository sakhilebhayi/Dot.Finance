<?php

namespace Tests\Support;

use App\Contracts\Bank\OpenDocument;
use App\Contracts\Bank\OpenDocumentSource;
use App\Models\Account;
use App\Models\Bank\BankStatementLine;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\GlSync;
use App\Support\Ledger\LedgerContext;

/** Stands in for the Sales module: one open invoice that a line with INV-0042 in it can settle. */
final class StubInvoiceSource implements OpenDocumentSource
{
    public static int $settled = 0;

    public function direction(): string
    {
        return 'in';
    }

    public function candidates(LedgerContext $context, array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            if (in_array('INV42', $line['docs'], true)) {
                $out[$line['id']] = [new OpenDocument('sales_document', 42, 'INV-0042', 'Acme Ltd', 2_500_000, 'ZAR', '2026-10-31', 'in', 'the narrative mentions INV-0042')];
            }
        }

        return $out;
    }

    public function settle(LedgerContext $context, User $user, BankStatementLine $line, OpenDocument $document, int $amountCents, string $idempotencyKey): Transaction
    {
        self::$settled++;
        $account = Account::query()->forContext($context)->findOrFail($line->account_id);
        $row = Transaction::create($account->ledgerOwner() + [
            'account_id' => $account->id, 'description' => 'Receipt for '.$document->number, 'amount_cents' => $amountCents, 'currency' => $account->currency,
            'type' => 'income', 'date' => $line->posted_on->toDateString(),
        ]);
        app(GlSync::class)->transaction($row);

        return $row;
    }
}
