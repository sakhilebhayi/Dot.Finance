<?php

namespace App\Contracts;

/**
 * Something a payment can be applied to: a sales invoice (money coming in) or a purchase bill
 * (money going out). Sales and Purchases implement it on their document models, and
 * PaymentAllocator checks every allocation against it.
 *
 * The open balance is always total minus the live allocations in payment_allocations, so the
 * total must already include credit notes and other adjustments (a credit note lowers the total
 * it reports); nothing is subtracted twice and nothing hides in another place.
 */
interface Allocatable
{
    /** The morph alias or class stored in payment_allocations.allocatable_type. */
    public function getMorphClass();

    /** The row id stored in payment_allocations.allocatable_id. */
    public function getKey();

    /** The most that can ever be allocated to it: what is owed in total, in minor units of allocatableCurrency(). */
    public function allocatableTotalCents(): int;

    public function allocatableCurrency(): string;

    /** The customer or supplier it is owed by or to, when it names one. */
    public function allocatablePartyId(): ?int;

    /** 'in' for something customers owe us (an invoice), 'out' for something we owe (a bill). */
    public function allocatableDirection(): string;

    /** {user_id, team_id} of the ledger it belongs to. @return array{user_id: ?int, team_id: ?int} */
    public function ledgerOwner(): array;
}
