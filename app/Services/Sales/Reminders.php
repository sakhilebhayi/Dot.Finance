<?php

namespace App\Services\Sales;

use App\Mail\SalesReminderMail;
use App\Models\Sales\SalesDocument;
use App\Models\Sales\SalesPartyPref;
use App\Models\Sales\SalesProfile;
use App\Models\Sales\SalesReminder;
use App\Models\Shared\Party;
use App\Models\User;
use App\Services\Sales\Gateways\ManualGateway;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Payment reminders on a schedule: N days before the due date, on it, and N days after. Safe to run any number of
 * times a day. Each (invoice, stage, offset) has exactly one row in the reminder log, created BEFORE the mail is
 * handed over, and its unique key is what stops a second run (or a second server) from sending again; a run that
 * dies between the log row and the mail therefore skips that reminder instead of sending it twice.
 *
 * A customer can opt out, a customer without an e-mail address is logged as skipped, and a reminder is never
 * sent for an invoice that has been paid since. "Handed to the mailer" is all the log claims.
 */
final class Reminders
{
    public function __construct(
        private readonly ApplicationEngine $engine,
        private readonly ShareLinks $links,
        private readonly ManualGateway $manual,
        private readonly SalesProfiles $profiles,
    ) {}

    /** Every ledger that has switched reminders on. @return array<string, array<string, int>> ledger key => counts */
    public function runAll(?CarbonImmutable $today = null): array
    {
        $out = [];
        foreach (LedgerContext::owningRowsIn('sales_profiles') as $context) {
            $profile = LedgerContext::run($context, fn () => SalesProfile::query()->forContext($context)->first());
            if ($profile !== null && $profile->reminders_enabled) {
                $out[$context->key()] = $this->runFor($context, $today);
            }
        }

        return $out;
    }

    /** @return array<string, int> */
    public function runFor(LedgerContext $context, ?CarbonImmutable $today = null, bool $dryRun = false): array
    {
        $today ??= CarbonImmutable::today();
        $counts = ['due' => 0, 'handed_to_mailer' => 0, 'skipped_opt_out' => 0, 'skipped_no_email' => 0, 'skipped_paid' => 0, 'already_logged' => 0, 'failed' => 0];

        LedgerContext::run($context, function () use ($context, $today, $dryRun, &$counts) {
            $profile = $this->profiles->for($context);
            $before = (int) $profile->reminder_before_days;
            $after = $profile->reminderAfterDays();

            $documents = SalesDocument::query()->forContext($context)->open()->whereNotNull('due_date')->with('party')->get();

            foreach ($documents as $document) {
                $delta = (int) CarbonImmutable::parse($document->due_date->toDateString())->diffInDays($today, false);   // negative: still to come
                [$stage, $offset] = match (true) {
                    $delta === 0 => ['on_due', 0],
                    $before > 0 && $delta === -$before => ['before_due', $before],
                    $delta > 0 && in_array($delta, $after, true) => ['after_due', $delta],
                    default => [null, 0],
                };
                if ($stage === null) {
                    continue;
                }

                $counts['due']++;
                if ($dryRun) {
                    continue;
                }
                $counts[$this->handle($context, $profile, $document, $stage, $offset, $today)]++;
            }
        });

        return $counts;
    }

    /**
     * Remind one customer about every invoice that is past its due date, now (the assistant's confirmed action). It uses the
     * log, so an invoice is reminded at most once for a given number of days late: confirming twice in a day sends once.
     *
     * @return array<string, int> the same counts as runFor()
     */
    public function remindCustomer(Party $customer, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $context = $customer->ledgerContext();
        $counts = ['due' => 0, 'handed_to_mailer' => 0, 'skipped_opt_out' => 0, 'skipped_no_email' => 0, 'skipped_paid' => 0, 'already_logged' => 0, 'failed' => 0];

        LedgerContext::run($context, function () use ($context, $customer, $today, &$counts) {
            $profile = $this->profiles->for($context);
            $documents = SalesDocument::query()->forContext($context)->open()->where('party_id', $customer->id)->whereNotNull('due_date')->where('due_date', '<', $today->toDateString())->with('party')->orderBy('due_date')->get();

            foreach ($documents as $document) {
                $late = (int) CarbonImmutable::parse($document->due_date->toDateString())->diffInDays($today);
                $counts['due']++;
                $counts[$this->handle($context, $profile, $document, 'after_due', $late, $today)]++;
            }
        });

        return $counts;
    }

    /** Whether a customer has asked not to be reminded. */
    public function optedOut(Party $party): bool
    {
        return (bool) SalesPartyPref::query()->forContext($party->ledgerContext())->where('party_id', $party->id)->value('reminders_opt_out');
    }

    public function setOptOut(Party $party, bool $optOut): SalesPartyPref
    {
        $context = $party->ledgerContext();
        $pref = SalesPartyPref::query()->forContext($context)->firstOrNew(['party_id' => $party->id]);
        if (! $pref->exists) {
            $pref->forceFill($context->ownerAttributes());
        }
        $pref->forceFill(['reminders_opt_out' => $optOut, 'opted_out_at' => $optOut ? now() : null])->save();

        return $pref;
    }

    /** @return string the outcome key */
    private function handle(LedgerContext $context, SalesProfile $profile, SalesDocument $document, string $stage, int $offset, CarbonImmutable $today): string
    {
        $owed = $this->engine->openBalance($document);
        if ($owed <= 0) {
            return 'skipped_paid';
        }

        $party = $document->party;
        try {
            // a savepoint, so a lost race (or a repeat run) is a clean refusal even inside someone else's transaction
            $log = DB::transaction(fn () => SalesReminder::query()->create($context->ownerAttributes() + [
                'document_id' => $document->id, 'party_id' => $document->party_id, 'stage' => $stage, 'offset_days' => $offset,
                'scheduled_for' => $today->toDateString(), 'outcome' => 'pending', 'to_address' => $party?->email,
            ]));
        } catch (UniqueConstraintViolationException) {
            return 'already_logged';
        }

        if ($party === null || $this->optedOut($party)) {
            $log->forceFill(['outcome' => 'skipped_opt_out', 'handled_at' => now()])->save();

            return 'skipped_opt_out';
        }
        if (blank($party->email)) {
            $log->forceFill(['outcome' => 'skipped_no_email', 'detail' => 'The customer has no e-mail address.', 'handled_at' => now()])->save();

            return 'skipped_no_email';
        }

        try {
            $creator = User::find($document->issued_by);
            $url = $creator !== null ? $this->links->forDocument($document, $creator)['url'] : null;
            Mail::to($party->email)->send(new SalesReminderMail($document, $stage, $offset, $owed, $profile->displayName() ?: 'Your supplier', $url, $this->manual->instructions($document), $profile->email));
        } catch (\Throwable $e) {
            report($e);
            $log->forceFill(['outcome' => 'failed', 'detail' => mb_substr($e->getMessage(), 0, 250), 'handled_at' => now()])->save();

            return 'failed';
        }

        $log->forceFill(['outcome' => 'handed_to_mailer', 'handled_at' => now()])->save();
        SalesTrail::event($document, 'reminder_sent', null, ['stage' => $stage, 'offset_days' => $offset, 'state' => 'handed to the mailer']);

        return 'handed_to_mailer';
    }
}
