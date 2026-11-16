<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Licences\PaystackWebhookEvent;
use App\Services\Licences\LicenceWebhookProcessor;
use App\Services\Licences\PaystackSignature;
use App\Services\Paystack\PaystackException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * POST /api/paystack/webhook. Order: size cap, signature (HMAC-SHA512 of the RAW body with the secret key, constant time; anything
 * unverified gets the same 401 and touches nothing), then the event is recorded once by key and processed. A duplicate delivery
 * answers 200 and does nothing. A failure we want Paystack to retry (verification down, an exception) answers 500 and leaves the
 * event unprocessed. The body is never logged (it holds customer details).
 */
class PaystackWebhookController extends Controller
{
    private const MAX_BODY_BYTES = 262144;

    public function __invoke(Request $request, LicenceWebhookProcessor $processor): JsonResponse
    {
        $raw = $request->getContent();

        if (strlen($raw) > self::MAX_BODY_BYTES) {
            return response()->json(['error' => 'payload_too_large'], 413);
        }

        if (! PaystackSignature::valid($raw, $request->header('x-paystack-signature'), config('paystack.secret_key'))) {
            Log::warning('Paystack webhook refused.', ['reason' => 'not configured, or bad signature']);

            return response()->json(['error' => 'invalid_request'], 401);
        }

        $body = json_decode($raw, true);

        if (! is_array($body) || ! is_string($body['event'] ?? null) || ! is_array($body['data'] ?? null)) {
            return response()->json(['error' => 'bad_request'], 400);
        }

        $type = $body['event'];
        $data = $body['data'];
        $key = $this->eventKey($type, $data, $raw);

        try {
            $row = PaystackWebhookEvent::query()->firstOrCreate(['event_key' => $key], [
                'event_type' => mb_substr($type, 0, 60), 'reference' => $this->reference($data), 'body_hash' => hash('sha256', $raw), 'status' => 'received',
            ]);
        } catch (UniqueConstraintViolationException) {
            $row = PaystackWebhookEvent::query()->where('event_key', $key)->firstOrFail();
        }

        if (in_array($row->status, ['processed', 'ignored'], true)) {
            return response()->json(['status' => 'duplicate']);
        }

        $row->increment('attempts');

        try {
            $outcome = $processor->handle($type, $data);
        } catch (PaystackException $e) {
            $row->forceFill(['error' => mb_substr('verification: '.$e->getMessage(), 0, 200)])->save();
            Log::warning('Paystack webhook could not be verified server-side; Paystack will retry.', ['event' => $type, 'transient' => $e->transient]);

            return response()->json(['error' => 'verification_unavailable'], 503);
        } catch (Throwable $e) {
            $row->forceFill(['error' => mb_substr(class_basename($e).': '.$e->getMessage(), 0, 200)])->save();
            report($e);

            return response()->json(['error' => 'processing_failed'], 500);
        }

        $row->forceFill(['status' => $outcome === LicenceWebhookProcessor::PROCESSED ? 'processed' : 'ignored', 'processed_at' => now(), 'error' => null])->save();

        return response()->json(['status' => $outcome]);
    }

    /** Paystack events carry no id of their own (UNVERIFIED), so the key is the event type plus the identity of what it is about. @param array<string, mixed> $data */
    private function eventKey(string $type, array $data, string $raw): string
    {
        $identity = match ($type) {
            'invoice.update', 'invoice.create', 'invoice.payment_failed' => ($data['invoice_code'] ?? '').'|'.($data['paid'] ?? '').'|'.($data['status'] ?? '').'|'.($data['transaction']['reference'] ?? ''),
            'subscription.create', 'subscription.disable', 'subscription.not_renew' => ($data['subscription_code'] ?? '').'|'.($data['status'] ?? ''),
            'refund.processed', 'refund.failed', 'refund.pending', 'refund.processing' => ($data['id'] ?? $data['refund_reference'] ?? '').'|'.($data['transaction_reference'] ?? ''),
            default => (string) ($data['reference'] ?? ''),
        };

        return mb_substr($type.':'.($identity !== '' && trim($identity, '|') !== '' ? $identity : 'body-'.hash('sha256', $raw)), 0, 190);
    }

    /** @param  array<string, mixed>  $data */
    private function reference(array $data): ?string
    {
        $ref = $data['reference'] ?? $data['transaction']['reference'] ?? $data['transaction_reference'] ?? null;

        return is_string($ref) ? mb_substr($ref, 0, 120) : null;
    }
}
