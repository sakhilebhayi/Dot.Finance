<?php

namespace App\Mail;

use App\Models\Intelligence\FinanceNotification;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** An internal notification e-mailed to a person who belongs to the ledger. Never sent to a customer or supplier. */
class FinanceNotificationMail extends Mailable
{
    public function __construct(public readonly FinanceNotification $notification, public readonly string $ledgerLabel) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '['.$this->ledgerLabel.'] '.$this->notification->title);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.finance-notification', with: [
            'title' => $this->notification->title,
            'body' => $this->notification->body,
            'url' => $this->notification->href,
            'ledger' => $this->ledgerLabel,
        ]);
    }
}
