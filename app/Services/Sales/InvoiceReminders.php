<?php

namespace App\Services\Sales;

use App\Contracts\Intelligence\InvoiceReminderSender;
use App\Models\Shared\Party;
use App\Models\User;

/**
 * The assistant's "remind this customer" action, through the Sales reminder engine: the same e-mail, the same opt-out, the same
 * log (one reminder per invoice and day, so confirming twice sends once). Customer-facing mail stays Sales' job.
 */
final class InvoiceReminders implements InvoiceReminderSender
{
    public function __construct(private readonly Reminders $reminders) {}

    public function available(): bool
    {
        return true;
    }

    public function send(Party $customer, User $by): string
    {
        $counts = $this->reminders->remindCustomer($customer);

        if ($counts['due'] === 0) {
            return $customer->name.' has no overdue invoice, so nothing was sent.';
        }
        if ($counts['handed_to_mailer'] > 0) {
            return 'Reminder handed to the mailer for '.$counts['handed_to_mailer'].($counts['handed_to_mailer'] === 1 ? ' overdue invoice' : ' overdue invoices').' of '.$customer->name.'.';
        }
        if ($counts['already_logged'] > 0 && $counts['failed'] === 0) {
            return 'A reminder for '.$customer->name.' was already sent today; nothing more was sent.';
        }
        if ($counts['skipped_opt_out'] > 0) {
            return $customer->name.' has asked not to be reminded, so nothing was sent.';
        }
        if ($counts['skipped_no_email'] > 0) {
            return $customer->name.' has no e-mail address, so nothing was sent.';
        }

        return 'The reminder could not be sent. Details are in the reminder log.';
    }
}
