<?php

namespace App\Services\Paystack;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The real adapter. Endpoints used (paths as recalled from Paystack's public API reference, which could not be fetched
 * by the build agent: see docs/R53_FINANCE.md): POST /plan, POST /transaction/initialize, GET /transaction/verify/:ref,
 * POST /subscription, POST /subscription/disable, POST /refund. Bearer auth with the secret key from the environment.
 * Nothing is logged from a request or a response body: they can hold customer details.
 */
final class HttpPaystackGateway implements PaystackGateway
{
    public function enabled(): bool
    {
        return filled(config('paystack.secret_key'));
    }

    public function createPlan(string $name, int $amountMinor, string $currency, string $interval): array
    {
        $data = $this->call('post', '/plan', ['name' => $name, 'amount' => $amountMinor, 'currency' => $currency, 'interval' => $interval]);

        return ['plan_code' => $this->string($data, 'plan_code')];
    }

    public function initializeTransaction(string $email, int $amountMinor, string $currency, string $reference, ?string $planCode, ?string $callbackUrl, array $metadata = []): array
    {
        $body = ['email' => $email, 'amount' => $amountMinor, 'currency' => $currency, 'reference' => $reference, 'metadata' => $metadata];
        if ($planCode !== null) {
            $body['plan'] = $planCode; // Paystack then ignores `amount` and charges the plan's amount
        }
        if ($callbackUrl !== null) {
            $body['callback_url'] = $callbackUrl;
        }

        $data = $this->call('post', '/transaction/initialize', $body);

        return ['authorization_url' => $this->string($data, 'authorization_url'), 'access_code' => $this->string($data, 'access_code'), 'reference' => $this->string($data, 'reference')];
    }

    public function createSubscription(string $customerCode, string $planCode, ?string $authorizationCode = null, ?string $startDate = null): array
    {
        $body = array_filter(['customer' => $customerCode, 'plan' => $planCode, 'authorization' => $authorizationCode, 'start_date' => $startDate], fn ($v) => $v !== null);
        $data = $this->call('post', '/subscription', $body);

        return ['subscription_code' => $this->string($data, 'subscription_code'), 'email_token' => isset($data['email_token']) ? (string) $data['email_token'] : null, 'status' => (string) ($data['status'] ?? 'active')];
    }

    public function disableSubscription(string $subscriptionCode, string $emailToken): void
    {
        $this->call('post', '/subscription/disable', ['code' => $subscriptionCode, 'token' => $emailToken]);
    }

    public function verifyTransaction(string $reference): array
    {
        $data = $this->call('get', '/transaction/verify/'.rawurlencode($reference));

        return [
            'status' => (string) ($data['status'] ?? ''),
            'reference' => (string) ($data['reference'] ?? $reference),
            'amount_minor' => (int) ($data['amount'] ?? 0),
            'currency' => strtoupper((string) ($data['currency'] ?? '')),
            'paid_at' => isset($data['paid_at']) ? (string) $data['paid_at'] : null,
            'customer_code' => isset($data['customer']['customer_code']) ? (string) $data['customer']['customer_code'] : null,
            'customer_email' => isset($data['customer']['email']) ? (string) $data['customer']['email'] : null,
            'authorization_code' => isset($data['authorization']['authorization_code']) ? (string) $data['authorization']['authorization_code'] : null,
            'plan_code' => isset($data['plan_object']['plan_code']) ? (string) $data['plan_object']['plan_code'] : (isset($data['plan']) && is_string($data['plan']) && $data['plan'] !== '' ? $data['plan'] : null),
            'metadata' => is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
        ];
    }

    public function refund(string $transactionReference, ?int $amountMinor = null, ?string $note = null): array
    {
        $body = array_filter(['transaction' => $transactionReference, 'amount' => $amountMinor, 'customer_note' => $note], fn ($v) => $v !== null);
        $data = $this->call('post', '/refund', $body);

        return ['status' => (string) ($data['status'] ?? 'pending'), 'refund_id' => isset($data['id']) ? (string) $data['id'] : null];
    }

    // ---- internals ---------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, array $body = []): array
    {
        if (! $this->enabled()) {
            throw new PaystackException('Paystack is not configured.');
        }

        try {
            $response = $this->client()->{$method}(rtrim((string) config('paystack.base_url'), '/').$path, $body);
        } catch (ConnectionException) {
            throw new PaystackException('Paystack could not be reached.', null, true);
        } catch (Throwable $e) {
            throw new PaystackException('Paystack call failed ('.class_basename($e).').', null, true);
        }

        $json = $response->json();

        if (! $response->successful() || ! is_array($json) || ($json['status'] ?? false) !== true || ! is_array($json['data'] ?? null)) {
            $status = $response->status();
            $message = is_array($json) && is_string($json['message'] ?? null) ? mb_substr($json['message'], 0, 200) : 'no readable answer';

            throw new PaystackException('Paystack refused the call ('.$status.'): '.$message, $status, $status >= 500 || $status === 429);
        }

        return $json['data'];
    }

    private function client(): PendingRequest
    {
        $timeout = max(1, (int) config('paystack.timeout', 15));

        return Http::withToken((string) config('paystack.secret_key'))->acceptJson()->asJson()->connectTimeout(min(5, $timeout))->timeout($timeout);
    }

    /** @param  array<string, mixed>  $data */
    private function string(array $data, string $key): string
    {
        if (! isset($data[$key]) || ! is_string($data[$key]) || $data[$key] === '') {
            throw new PaystackException('Paystack answered without '.$key.'.');
        }

        return $data[$key];
    }
}
