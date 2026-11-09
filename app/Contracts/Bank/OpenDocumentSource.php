<?php

namespace App\Contracts\Bank;

use App\Models\Bank\BankStatementLine;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Ledger\LedgerContext;
use App\Support\Shared\Exceptions\AllocationRejected;

/**
 * What the bank module needs from Sales and Purchases to turn a statement line into "this settles invoice INV-0042".
 *
 * Sales implements it for money in (customer invoices), Purchases for money out (supplier bills); each tags its
 * implementation in a service provider:
 *
 *     $this->app->tag([SalesOpenDocuments::class], 'bank.document-sources');
 *
 * and the bank module asks every tagged source (App\Services\Bank\DocumentSources). With no source tagged nothing is
 * offered and the "settle an invoice or bill" action does not appear: the bank module never reads another module's tables.
 *
 * settle() is where the real work is, and it belongs to the owning module because only it can post the right journal
 * (Dr bank / Cr receivables for a customer payment, Dr payables / Cr bank for a supplier payment). It must record the payment
 * with PaymentRecorder (idempotent on $idempotencyKey), allocate it with PaymentAllocator, write the cash-ledger row and post,
 * all inside the caller's database transaction, and return the cash-ledger row so the bank module can match it to the line.
 */
interface OpenDocumentSource
{
    /** 'in' for a source of customer invoices, 'out' for supplier bills. */
    public function direction(): string;

    /**
     * Open documents each statement line might settle.
     *
     * @param  list<array{id: int, cents: int, currency: string, text: string, docs: list<string>}>  $lines  signed cents; text is the lower-cased narrative; docs are document-looking numbers found in it (INV42)
     * @return array<int, list<OpenDocument>> line id => candidates, best first
     */
    public function candidates(LedgerContext $context, array $lines): array;

    /**
     * Settle $document with the money on $line, for $amountCents (never more than the line or the open balance).
     *
     * @throws AllocationRejected when the settlement is not allowed
     */
    public function settle(LedgerContext $context, User $user, BankStatementLine $line, OpenDocument $document, int $amountCents, string $idempotencyKey): Transaction;
}
