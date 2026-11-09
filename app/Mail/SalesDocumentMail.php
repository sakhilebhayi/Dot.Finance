<?php

namespace App\Mail;

use App\Models\Sales\SalesDocument;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A sales document (invoice, credit note, quote ...) with its PDF and a link to view it online. */
class SalesDocumentMail extends Mailable
{
    public function __construct(
        public string $subjectLine,
        public ?string $body,
        public SalesDocument $document,
        public string $businessName,
        public ?string $viewUrl,
        public string $pdf,
        public string $filename,
        public ?string $replyTo = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
            replyTo: $this->replyTo ? [new Address($this->replyTo)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.sales.document');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->pdf, $this->filename)->withMime('application/pdf')];
    }
}
