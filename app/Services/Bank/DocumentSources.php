<?php

namespace App\Services\Bank;

use App\Contracts\Bank\OpenDocument;
use App\Contracts\Bank\OpenDocumentSource;
use App\Exceptions\Bank\ReconciliationRefused;
use App\Models\Bank\BankStatementLine;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Ledger\LedgerContext;

/**
 * Every OpenDocumentSource the application has tagged (Sales for customer invoices, Purchases for supplier bills). With none,
 * available() is false and the bank screens simply do not offer "settle an invoice or bill".
 */
class DocumentSources
{
    /** @param  iterable<OpenDocumentSource>  $sources */
    public function __construct(private readonly iterable $sources = []) {}

    public function available(): bool
    {
        foreach ($this->sources as $source) {
            return true;
        }

        return false;
    }

    /**
     * @param  list<array{id: int, cents: int, currency: string, text: string, docs: list<string>}>  $lines
     * @return array<int, list<OpenDocument>>
     */
    public function candidates(LedgerContext $context, array $lines): array
    {
        $out = [];

        foreach ($this->sources as $source) {
            $mine = array_values(array_filter($lines, fn ($l) => ($source->direction() === 'in') === ($l['cents'] > 0)));

            if ($mine === []) {
                continue;
            }

            foreach ($source->candidates($context, $mine) as $lineId => $documents) {
                $out[$lineId] = array_values([...($out[$lineId] ?? []), ...$documents]);
            }
        }

        return $out;
    }

    public function settle(LedgerContext $context, User $user, BankStatementLine $line, OpenDocument $document, int $amountCents, string $idempotencyKey): Transaction
    {
        foreach ($this->sources as $source) {
            if ($source->direction() === $document->direction) {
                return $source->settle($context, $user, $line, $document, $amountCents, $idempotencyKey);
            }
        }

        throw new ReconciliationRefused('unavailable', 'Invoices and bills cannot be settled from the bank screens yet: the sales and purchases modules have not registered themselves with the bank module.');
    }
}
