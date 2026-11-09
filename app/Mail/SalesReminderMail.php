<?php

namespace App\Mail;

use App\Models\Sales\SalesDocument;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A payment reminder for one invoice: before it is due, on the day, or after. Plain wording, no pressure tactics. */
class SalesReminderMail extends Mailable
{
    public function __construct(
        public SalesDocument $document,
        public string $stage,
        public int $offsetDays,
        public int $owedCents,
        public string $businessName,
        public ?string $viewUrl,
        public ?string $paymentInstructions,
        public ?string $replyToAddress = null,
    ) {}

    public function envelope(): Envelope
    {
        $subject = match ($this->stage) {
            'before_due' => 'Invoice '.$this->document->number.' falls due on '.$this->document->due_date->format('j M Y'),
            'on_due' => 'Invoice '.$this->document->number.' is due today',
            default => 'Invoice '.$this->document->number.' is '.$this->offsetDays.' days past due',
        };

        return new Envelope(subject: $subject.' ('.$this->businessName.')', replyTo: $this->replyToAddress ? [new Address($this->replyToAddress)] : []);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.sales.reminder');
    }
}
