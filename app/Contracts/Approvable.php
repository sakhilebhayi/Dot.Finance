<?php

namespace App\Contracts;

use App\Models\Controls\ApprovalRequest;
use App\Support\Ledger\LedgerContext;

/**
 * The contract between the approvals engine (App\Services\Controls\ApprovalService) and any module
 * whose records can need a second pair of eyes: a supplier bill, an expense claim, a payment, a
 * manual journal, an invoice, a period close, a budget.
 *
 * A module makes its model implement this, then:
 *
 *   1. asks ApprovalService::requiresApproval($kind, $amountInLedgerBaseCurrencyCents, $currency)
 *      before it lets the subject move on (issue, post, pay, close);
 *   2. if it does, calls $service->request($user, $subject) and leaves the subject in an
 *      "awaiting approval" state;
 *   3. reacts in onApprovalDecided() (called inside the decision's transaction, so the subject
 *      and the decision change together or not at all), and/or listens for the
 *      App\Events\ApprovalDecided event (dispatched after commit) for side effects such as
 *      notifications;
 *   4. before doing the guarded thing, calls ApprovalService::isApproved($subject), which checks
 *      the approval still matches the subject's current amount.
 *
 * Models that use HasLedgerContext already provide ledgerContext().
 */
interface Approvable
{
    /** One of App\Enums\ApprovalSubject: expense, bill, payment, journal, invoice, period_close, budget. */
    public function approvalSubjectKind(): string;

    /** What is being approved, in minor units, right now. Re-read when the decision is made. */
    public function approvalAmountCents(): int;

    /** The currency of approvalAmountCents(), null for a subject with no amount (a period close). */
    public function approvalCurrency(): ?string;

    /** A short label for the inbox. No free text a person typed: a number and a kind ("Bill BL-0012"). */
    public function approvalLabel(): string;

    /** The ledger the subject belongs to (HasLedgerContext provides it). */
    public function ledgerContext(): LedgerContext;

    /**
     * Called when the request reaches a final state, inside the same database transaction as the
     * decision: $request->status is 'approved', 'rejected' or 'cancelled'. Throw to refuse the
     * decision (it is rolled back).
     */
    public function onApprovalDecided(ApprovalRequest $request): void;
}
