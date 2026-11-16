<?php

namespace App\Services\Licences;

use App\Jobs\PushLicenceEvent;
use App\Models\Licences\LicenceBatch;
use App\Models\Licences\LicenceOutboxEvent;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The confirmation channel to InfoDot (CONTRACT.md step 4): POST config('licences.push.url') with kind "licence_batch".
 * Signed like every R-50 push: HMAC-SHA256 over "{timestamp}.{raw body}" as "sha256=<hex>" in X-InfoDot-Signature with the unix
 * time in X-InfoDot-Timestamp, made at SEND time so a retry carries a fresh timestamp. The event_id is the idempotency key on both sides.
 *
 * An event is written here first (inside the caller's transaction) and delivered afterwards: a push that fails is retried with
 * backoff, stays visible (status, attempts, last error) and is never deleted. InfoDot answers applied / duplicate / ignored: all
 * three mean "received". A 400 or 422 means InfoDot refuses the content: it is parked as `rejected` for a person, not hammered.
 */
final class LicenceOutbox
{
    /** Seconds before the next try, by attempts made so far. Capped at the last value. */
    private const BACKOFF = [30, 60, 120, 300, 600, 1800, 3600, 7200, 14400, 21600];

    /** The event types InfoDot accepts for kind licence_batch. */
    public const TYPES = ['paid', 'renewed', 'payment_failed', 'refunded', 'cancelled'];

    /**
     * Queue one confirmation. Idempotent on $eventId: the same id twice stores and sends once.
     *
     * @param  array{amount_minor?: ?int, period_start?: ?CarbonInterface, period_end?: ?CarbonInterface, reference: string}  $facts
     */
    public function emit(LicenceBatch $batch, string $type, string $eventId, array $facts): LicenceOutboxEvent
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Unknown licence event type.');
        }

        $payload = [
            'kind' => 'licence_batch', 'event_id' => $eventId, 'type' => $type, 'batch_uuid' => $batch->batch_uuid, 'team_uuid' => $batch->team_uuid,
            'quantity' => $batch->quantity, 'interval' => $batch->interval_raw,
            'period_start' => ($facts['period_start'] ?? $batch->period_start)?->utc()->toIso8601String(),
            'period_end' => ($facts['period_end'] ?? $batch->period_end)?->utc()->toIso8601String(),
            'amount_minor' => $facts['amount_minor'] ?? $batch->total_minor, 'currency' => $batch->currency, 'paystack_reference' => $facts['reference'],
        ];

        try {
            $event = LicenceOutboxEvent::query()->create([
                'event_id' => $eventId, 'batch_uuid' => $batch->batch_uuid, 'type' => $type,
                'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'status' => LicenceOutboxEvent::PENDING, 'next_attempt_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return LicenceOutboxEvent::query()->where('event_id', $eventId)->firstOrFail();
        }

        // Only after the surrounding transaction commits; the sweep covers a lost job either way.
        PushLicenceEvent::dispatch($event->id)->afterCommit();

        return $event;
    }

    public function enabled(): bool
    {
        return filled(config('licences.push.url')) && filled(config('licences.push.secret'));
    }

    /** Deliver one row now if it is due. Returns the row's status afterwards. Safe to call twice at once: only one wins the claim. */
    public function deliver(int $id): string
    {
        $claimed = LicenceOutboxEvent::query()->whereKey($id)->where('status', LicenceOutboxEvent::PENDING)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->update(['next_attempt_at' => now()->addSeconds(120)]); // a lease: nobody else sends this row for two minutes

        $event = LicenceOutboxEvent::query()->find($id);

        if ($event === null) {
            return 'missing';
        }

        if ($claimed === 0) {
            return $event->status;
        }

        if (! $this->enabled()) {
            $this->failed($event, null, 'push not configured', retry: true);

            return $event->status;
        }

        $body = (string) $event->payload;
        $timestamp = time();

        try {
            $response = Http::timeout(max(1, (int) config('licences.push.timeout', 8)))->connectTimeout(4)->acceptJson()
                ->withHeaders(['X-InfoDot-Timestamp' => (string) $timestamp, 'X-InfoDot-Signature' => LicenceSignature::sign((string) config('licences.push.secret'), $timestamp, $body)])
                ->withBody($body, 'application/json')
                ->post((string) config('licences.push.url'));
        } catch (Throwable $e) {
            $this->failed($event, null, 'unreachable ('.class_basename($e).')', retry: true);

            return $event->status;
        }

        $status = $response->status();

        if ($status >= 200 && $status < 300) {
            $event->forceFill([
                'status' => LicenceOutboxEvent::SENT, 'sent_at' => now(), 'attempts' => $event->attempts + 1, 'last_http_status' => $status, 'last_error' => null,
                'next_attempt_at' => null, 'infodot_answer' => mb_substr((string) ($response->json('status') ?? ''), 0, 24) ?: null,
            ])->save();

            return $event->status;
        }

        // 400 and 422: the content is refused and will be refused again. 401, 404, 409, 429 and 5xx may clear (config, deploy, load).
        $this->failed($event, $status, 'InfoDot answered '.$status, retry: ! in_array($status, [400, 422], true));

        return $event->status;
    }

    /** Every pending row that is due, oldest first. @return int how many were attempted */
    public function sweep(int $limit = 100): int
    {
        $ids = LicenceOutboxEvent::query()->where('status', LicenceOutboxEvent::PENDING)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')->limit($limit)->pluck('id');

        foreach ($ids as $id) {
            $this->deliver((int) $id);
        }

        return $ids->count();
    }

    /** Put dead and rejected rows back in the queue (a person decided to try again). */
    public function requeue(?string $eventId = null): int
    {
        return LicenceOutboxEvent::query()->whereIn('status', [LicenceOutboxEvent::DEAD, LicenceOutboxEvent::REJECTED])
            ->when($eventId, fn ($q) => $q->where('event_id', $eventId))
            ->update(['status' => LicenceOutboxEvent::PENDING, 'attempts' => 0, 'next_attempt_at' => now(), 'last_error' => null]);
    }

    /** @return array<string, int> row counts by status */
    public function counts(): array
    {
        return LicenceOutboxEvent::query()->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
    }

    public function backoffFor(int $attempts): int
    {
        return self::BACKOFF[min(max(0, $attempts - 1), count(self::BACKOFF) - 1)];
    }

    private function failed(LicenceOutboxEvent $event, ?int $http, string $reason, bool $retry): void
    {
        $attempts = $event->attempts + 1;
        $max = max(1, (int) config('licences.push.max_attempts', 12));
        $dead = $retry && $attempts >= $max;

        $event->forceFill([
            'attempts' => $attempts, 'last_http_status' => $http, 'last_error' => mb_substr($reason, 0, 200),
            'status' => ! $retry ? LicenceOutboxEvent::REJECTED : ($dead ? LicenceOutboxEvent::DEAD : LicenceOutboxEvent::PENDING),
            'next_attempt_at' => $retry && ! $dead ? now()->addSeconds($this->backoffFor($attempts)) : null,
        ])->save();

        Log::warning('Licence confirmation to InfoDot did not go through.', ['event_id' => $event->event_id, 'type' => $event->type, 'attempts' => $attempts, 'http' => $http, 'outcome' => $event->status]);
    }
}
