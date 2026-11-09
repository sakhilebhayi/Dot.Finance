<?php

namespace App\Services\Bank;

use App\Models\Bank\BankReconciliation;
use App\Models\Bank\BankReconciliationEvent;
use App\Models\User;
use App\Support\FinanceAudit;

/** The two places a reconciliation leaves a trace: its own append-only event log, and the ledger's audit log. */
trait RecordsReconciliationHistory
{
    /** @param  array<string, mixed>  $data */
    protected function event(BankReconciliation $session, string $event, ?User $user, array $data = []): void
    {
        BankReconciliationEvent::create($session->ledgerOwner() + [
            'bank_reconciliation_id' => $session->id,
            'account_id' => $session->account_id,
            'event' => $event,
            'actor_id' => $user?->id,
            'data' => $data === [] ? null : $data,
        ]);
    }

    /** @param  array<string, mixed>  $changes  numbers, ids and enums only: never free text a person typed */
    protected function audit(BankReconciliation $session, string $action, array $changes, ?User $user = null): void
    {
        FinanceAudit::record($session->ledgerOwner(), $action, 'bank_reconciliation', $session->id, $session->auditLabel(), $changes, 'bank-reconciliation:'.$session->id, $session->currency, $user?->id);
    }
}
