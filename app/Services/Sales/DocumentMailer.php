<?php

namespace App\Services\Sales;

use App\Mail\SalesDocumentMail;
use App\Models\Sales\SalesDocument;
use App\Models\User;
use App\Services\Sales\Support\SalesViolation;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

/**
 * Sends a document through the application's own mailer. What it knows is only that the message was handed to the
 * mailer without an error; it records exactly that ("handed to the mailer") and never says "delivered" or "read".
 * Whether the customer opened it is only known if they follow the link, and then it says "viewed", not "read".
 */
final class DocumentMailer
{
    public function __construct(private readonly DocumentRenderer $renderer, private readonly ShareLinks $links, private readonly SalesProfiles $profiles) {}

    /** @throws SalesViolation */
    public function send(SalesDocument $document, User $by, string $to, ?string $message = null, bool $includeLink = true): SalesDocument
    {
        if (! $document->isSealed() || $document->isVoid()) {
            throw new SalesViolation('Only an issued document that is not void can be sent.', 'sealed');
        }
        if (Validator::make(['to' => $to], ['to' => ['required', 'email:rfc', 'max:255']])->fails()) {
            throw new SalesViolation('That is not a valid e-mail address.', 'email');
        }

        $profile = $this->profiles->for($document->ledgerContext());
        $url = $includeLink ? $this->links->forDocument($document, $by)['url'] : null;

        try {
            Mail::to($to)->send(new SalesDocumentMail(
                subjectLine: $document->title().' '.$document->number.' from '.($profile->displayName() ?: 'us'),
                body: $message, document: $document, businessName: $profile->displayName() ?: 'Your supplier', viewUrl: $url,
                pdf: $this->renderer->pdf($document), filename: $this->renderer->filename($document), replyToAddress: $profile->email,
            ));
        } catch (\Throwable $e) {
            SalesTrail::event($document, 'email_failed', $by->id, ['to' => $to]);
            report($e);
            throw new SalesViolation('The e-mail could not be handed to the mail system. Nothing was sent.', 'mail_failed');
        }

        $document->forceFill(['sent_at' => now()])->save();
        SalesTrail::event($document, 'emailed', $by->id, ['to' => $to, 'state' => 'handed to the mailer']);

        return $document;
    }
}
