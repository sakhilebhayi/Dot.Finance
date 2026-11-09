<?php

namespace App\Services\Intelligence;

use App\Enums\Capability;
use App\Mail\FinanceNotificationMail;
use App\Models\Intelligence\FinanceNotification;
use App\Models\Intelligence\NotificationPreference;
use App\Models\User;
use App\Services\Integrations\NotifyClient;
use App\Support\Ledger\LedgerAccess;
use App\Support\Ledger\LedgerContext;
use App\Support\Links;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The one way a message reaches a person: stored for the in-app bell, then (by their preferences) e-mailed from the
 * app mailer and forwarded to Dot.Notify when that is connected. Recipients are always members of the ledger who
 * may see finance; the same thing is never sent twice (the dedupe key is unique per ledger and person). Customer
 * payment reminders are owned by the Sales module: this class carries their record (kind payment_reminder) if
 * Sales wants one, but it never e-mails a customer.
 */
final class FinanceNotifier
{
    public function __construct(private readonly NotifyClient $notify) {}

    /** Members of the ledger who hold $capability. @return Collection<int, User> */
    public function recipientsWith(LedgerContext $context, Capability $capability): Collection
    {
        $users = $context->isCompany()
            ? ($context->team()?->allUsers() ?? collect())
            : collect(array_filter([User::find($context->userId)]));

        return $users->unique('id')->filter(fn (User $u) => LedgerAccess::allows($capability, $u, $context))->values();
    }

    public function preferenceFor(LedgerContext $context, User $user, string $kind): array
    {
        $row = NotificationPreference::query()->forContext($context)->where('member_user_id', $user->id)->where('kind', $kind)->first();

        return $row ? ['in_app' => $row->in_app, 'email' => $row->email, 'notify' => $row->notify] : NotificationPreference::DEFAULTS;
    }

    /** @return FinanceNotification|null null when it was already sent, or the person is not allowed to see this ledger */
    public function send(LedgerContext $context, User $recipient, string $kind, string $title, string $body, ?string $href, string $dedupeKey, string $severity = 'info'): ?FinanceNotification
    {
        if (! array_key_exists($kind, FinanceNotification::KINDS) || ! LedgerAccess::allows(Capability::ViewFinance, $recipient, $context)) {
            return null;
        }

        $prefs = $this->preferenceFor($context, $recipient, $kind);

        $key = mb_substr($dedupeKey, 0, 120);

        if (FinanceNotification::query()->forContext($context)->where('recipient_id', $recipient->id)->where('dedupe_key', $key)->exists()) {
            return null;
        }

        try {
            // A savepoint (a nested transaction) so that losing a race to the unique index does not poison the caller's
            // transaction: on PostgreSQL a failed statement aborts the whole transaction otherwise.
            $note = DB::transaction(fn () => FinanceNotification::create($context->ownerAttributes() + [
                'recipient_id' => $recipient->id,
                'kind' => $kind,
                'severity' => $severity,
                'title' => mb_substr($title, 0, 200),
                'body' => $body,
                'href' => ($relative = Links::relative($href)) ? mb_substr($relative, 0, 300) : null,
                'dedupe_key' => $key,
                'email_status' => 'none',
                'notify_status' => 'none',
                // Someone who switched the bell off for this kind still has the record, but it does not badge.
                'read_at' => $prefs['in_app'] ? null : now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        if ($prefs['email'] && config('intelligence.notifications.email_enabled')) {
            try {
                Mail::to($recipient->email)->send(new FinanceNotificationMail($note, $context->label()));
                $note->forceFill(['emailed_at' => now(), 'email_status' => 'sent'])->save();
            } catch (\Throwable $e) {
                Log::warning('Notification e-mail failed.', ['error' => class_basename($e)]);
                $note->forceFill(['email_status' => 'failed'])->save();
            }
        } else {
            $note->forceFill(['email_status' => 'skipped'])->save();
        }

        if ($prefs['notify'] && $this->notify->enabled()) {
            $status = $this->notify->send('finance.'.$kind, $recipient->email, ['title' => $title, 'body' => $body, 'url' => $href, 'ledger' => $context->label(), 'severity' => $severity]);
            $note->forceFill(['notify_status' => $status])->save();
        }

        return $note;
    }

    public function markRead(FinanceNotification $note): void
    {
        if ($note->read_at === null) {
            $note->forceFill(['read_at' => now()])->save();
        }
    }
}
