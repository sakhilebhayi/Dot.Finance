<?php

namespace App\Services\Intelligence\Sources;

use App\Contracts\Intelligence\InvoiceReminderSender;
use App\Models\Shared\Party;
use App\Models\User;
use LogicException;

/** Used until the Sales module binds the real sender. */
final class NullInvoiceReminderSender implements InvoiceReminderSender
{
    public function available(): bool
    {
        return false;
    }

    public function send(Party $customer, User $by): string
    {
        throw new LogicException('No invoice reminder sender is installed.');
    }
}
