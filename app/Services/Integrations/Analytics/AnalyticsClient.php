<?php

namespace App\Services\Integrations\Analytics;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one daily snapshot to Dot.Analytics (POST config('integrations.analytics.url'), which ends /api/ingest/snapshots).
 * Same shape as the Dot.Pulse and Dot.Auction publishers: only ever called from a queued job, a short timeout, nothing
 * here throws into the caller, a circuit breaker leaves a struggling Analytics alone, and without a url and secret it
 * does nothing at all.
 *
 * Signing (Analytics' ServerIngestSignature): HMAC-SHA256 over "{timestamp}.{raw body}" with the platform's secret, as
 * "sha256=<hex>" in X-InfoDot-Signature with the unix time in X-InfoDot-Timestamp, made at SEND time so a retry carries
 * a fresh timestamp. The day it describes (taken_at) is fixed in the payload, so a retry is an idempotent replay upstream.
 */
class AnalyticsClient
{
    public const DONE = 'done';

    public const RETRY = 'retry';

    public const GIVE_UP = 'give_up';

    private const FAILURES_KEY = 'finance:analytics:failures';

    private const OPEN_KEY = 'finance:analytics:open';

    public function enabled(): bool
    {
        return filled(config('integrations.analytics.url')) && filled(config('integrations.analytics.secret'));
    }

    /** @param  array<string, mixed>  $payload */
    public function deliver(array $payload): string
    {
        if (! $this->enabled()) {
            return self::GIVE_UP;
        }

        if ($this->circuitOpen()) {
            return self::RETRY;
        }

        try {
            $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $timestamp = (string) now()->timestamp;
            $timeout = (int) config('integrations.analytics.timeout', 5);

            $response = Http::timeout($timeout)->connectTimeout($timeout)->acceptJson()
                ->withHeaders([
                    'X-InfoDot-Timestamp' => $timestamp,
                    'X-InfoDot-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, (string) config('integrations.analytics.secret')),
                ])
                ->withBody($body, 'application/json')
                ->post((string) config('integrations.analytics.url'));
        } catch (ConnectionException) {
            Log::warning('Dot.Analytics unreachable; will retry.');
            $this->recordFailure();

            return self::RETRY;
        } catch (Throwable $e) {
            Log::warning('Dot.Analytics call failed unexpectedly; will retry.', ['error' => class_basename($e)]);
            $this->recordFailure();

            return self::RETRY;
        }

        $status = $response->status();

        if ($status >= 200 && $status < 300) {
            $this->recordSuccess();

            return self::DONE;
        }

        if ($status === 429 || $status >= 500) {
            Log::warning('Dot.Analytics unavailable; will retry.', ['status' => $status]);
            $this->recordFailure();

            return self::RETRY;
        }

        // 401/403: the secret is wrong; 404: the company is unknown; 422: the payload was refused. Retrying cannot fix
        // these (and a platform missing from Analytics' catalogue is refused with a generic 401).
        $this->recordSuccess();
        Log::error('Dot.Analytics refused the snapshot.', ['status' => $status, 'platform' => $payload['platform'] ?? null]);

        return self::GIVE_UP;
    }

    public function circuitOpen(): bool
    {
        try {
            return (bool) Cache::get(self::OPEN_KEY, false);
        } catch (Throwable) {
            return false;
        }
    }

    private function recordFailure(): void
    {
        try {
            $threshold = max(1, (int) config('integrations.analytics.breaker.threshold', 3));
            $cooldown = max(1, (int) config('integrations.analytics.breaker.cooldown', 300));

            Cache::add(self::FAILURES_KEY, 0, now()->addDay());
            $failures = (int) Cache::increment(self::FAILURES_KEY);

            if ($failures >= $threshold) {
                Cache::put(self::OPEN_KEY, true, now()->addSeconds($cooldown));
                Log::warning('Dot.Analytics circuit opened.', ['cooldown_seconds' => $cooldown]);
            }
        } catch (Throwable) {
            // a broken cache must never stop delivery
        }
    }

    private function recordSuccess(): void
    {
        try {
            Cache::forget(self::FAILURES_KEY);
            Cache::forget(self::OPEN_KEY);
        } catch (Throwable) {
            // see circuitOpen()
        }
    }
}
