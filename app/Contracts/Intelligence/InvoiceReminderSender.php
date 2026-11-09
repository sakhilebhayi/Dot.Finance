<?php

namespace App\Contracts\Intelligence;

use App\Models\Shared\Party;
use App\Models\User;

/**
 * Sends a payment reminder to a customer. Owned by the Sales module: customer-facing mail is its job, not the
 * assistant's. The default says it is not available, and the assistant then offers no reminder draft.
 */
interface InvoiceReminderSender
{
    public function available(): bool;

    /** Send (or queue) the reminder for the customer's overdue invoices. Return a short result sentence. */
    public function send(Party $customer, User $by): string;
}
