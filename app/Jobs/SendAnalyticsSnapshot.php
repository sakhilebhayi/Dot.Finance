<?php

namespace App\Jobs;

use App\Services\Integrations\Analytics\AnalyticsClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one daily snapshot to Dot.Analytics off every request path. Three tries with backoff while Analytics is
 * down, a quiet give-up on a refusal, and it never throws: a snapshot that cannot be sent must not break anything.
 */
class SendAnalyticsSnapshot implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @param  array<string, mixed>  $payload */
    public function __construct(public array $payload) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(AnalyticsClient $client): void
    {
        try {
            $outcome = $client->deliver($this->payload);
        } catch (Throwable) {
            $outcome = AnalyticsClient::RETRY;
        }

        if ($outcome !== AnalyticsClient::RETRY || ! $this->job) {
            return;
        }

        try {
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff()[min($this->attempts() - 1, count($this->backoff()) - 1)] ?? 60);

                return;
            }

            Log::warning('Dot.Analytics snapshot abandoned after repeated failures.', ['taken_at' => $this->payload['taken_at'] ?? null]);
        } catch (Throwable) {
            // nothing here may throw
        }
    }
}
