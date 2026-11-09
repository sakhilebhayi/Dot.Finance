<?php

namespace App\Services\Integrations;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Forwards a notification to Dot.Notify's inbound webhook (POST /webhooks/{token}).
 *
 * What exists in Dot.Notify (read in ~/Dot/Dot.Notify, WebhookInboundController): a webhook row has an endpoint
 * token and a signing secret; the caller sends JSON and signs the RAW body with HMAC-SHA256 using that secret, in
 * X-Dot-Signature. The `event` field is looked up in the webhook's event_map, which names the trigger event of an
 * active NotifyRule; the rule's channel and template do the delivery, and `recipient` (or `email`) is the address.
 * So delivery needs three things created in Dot.Notify by its owner: the webhook, an event_map entry for each
 * `finance.*` event below, and a rule with a channel and template. Without them Notify answers 200 but routes nothing.
 *
 * Inert unless NOTIFY_WEBHOOK_URL (which contains the endpoint token) and NOTIFY_WEBHOOK_SECRET are both set. It
 * never throws: a Notify outage is a "failed" status on the notification, never an error for the person.
 */
final class NotifyClient
{
    public function enabled(): bool
    {
        return filled(config('integrations.notify.url')) && filled(config('integrations.notify.secret'));
    }

    /**
     * @param  array<string, scalar|null>  $data  flat scalar values only; they become the template's variables
     * @return 'sent'|'failed'|'skipped'
     */
    public function send(string $event, string $recipientEmail, array $data): string
    {
        if (! $this->enabled()) {
            return 'skipped';
        }

        $body = (string) json_encode(
            ['event' => $event, 'recipient' => $recipientEmail] + array_filter($data, 'is_scalar'),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        try {
            $timeout = (int) config('integrations.notify.timeout', 5);
            $response = Http::timeout($timeout)->connectTimeout($timeout)->acceptJson()
                ->withHeaders(['X-Dot-Signature' => hash_hmac('sha256', $body, (string) config('integrations.notify.secret'))])
                ->withBody($body, 'application/json')
                ->post((string) config('integrations.notify.url'));
        } catch (ConnectionException $e) {
            Log::warning('Dot.Notify unreachable.', ['event' => $event]);

            return 'failed';
        } catch (Throwable $e) {
            Log::warning('Dot.Notify call failed.', ['event' => $event, 'error' => class_basename($e)]);

            return 'failed';
        }

        if (! $response->successful()) {
            Log::warning('Dot.Notify refused the event.', ['event' => $event, 'status' => $response->status()]);

            return 'failed';
        }

        return 'sent';
    }
}
