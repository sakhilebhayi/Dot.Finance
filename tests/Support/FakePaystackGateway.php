<?php

namespace Tests\Support;

use App\Services\Paystack\PaystackException;
use App\Services\Paystack\PaystackGateway;

/** In-memory Paystack for tests: records every call, and a test decides which transactions are paid. */
final class FakePaystackGateway implements PaystackGateway
{
    public bool $on = true;

    public int $plansCreated = 0;

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, array<string, mixed>> reference => verify answer */
    public array $transactions = [];

    /** @var array<string, string> */
    public array $plans = [];

    public ?PaystackException $failNext = null;

    public function enabled(): bool
    {
        return $this->on;
    }

    public function createPlan(string $name, int $amountMinor, string $currency, string $interval): array
    {
        $this->record('createPlan', compact('name', 'amountMinor', 'currency', 'interval'));
        $code = 'PLN_fake'.(++$this->plansCreated);
        $this->plans[$code] = $interval;

        return ['plan_code' => $code];
    }

    public function initializeTransaction(string $email, int $amountMinor, string $currency, string $reference, ?string $planCode, ?string $callbackUrl, array $metadata = []): array
    {
        $this->record('initializeTransaction', compact('email', 'amountMinor', 'currency', 'reference', 'planCode', 'callbackUrl', 'metadata'));
        $this->transactions[$reference] = ['status' => 'abandoned', 'reference' => $reference, 'amount_minor' => $amountMinor, 'currency' => $currency, 'paid_at' => null,
            'customer_code' => 'CUS_fake', 'customer_email' => $email, 'authorization_code' => 'AUTH_fake', 'plan_code' => $planCode, 'metadata' => $metadata];

        return ['authorization_url' => 'https://checkout.paystack.test/'.$reference, 'access_code' => 'acc_'.substr(md5($reference), 0, 8), 'reference' => $reference];
    }

    public function createSubscription(string $customerCode, string $planCode, ?string $authorizationCode = null, ?string $startDate = null): array
    {
        $this->record('createSubscription', compact('customerCode', 'planCode', 'authorizationCode', 'startDate'));

        return ['subscription_code' => 'SUB_fake'.count($this->calls), 'email_token' => 'tok_fake', 'status' => 'active'];
    }

    public function disableSubscription(string $subscriptionCode, string $emailToken): void
    {
        $this->record('disableSubscription', compact('subscriptionCode', 'emailToken'));
    }

    public function verifyTransaction(string $reference): array
    {
        $this->record('verifyTransaction', compact('reference'));

        return $this->transactions[$reference] ?? throw new PaystackException('Paystack refused the call (404): Transaction reference not found', 404);
    }

    public function refund(string $transactionReference, ?int $amountMinor = null, ?string $note = null): array
    {
        $this->record('refund', compact('transactionReference', 'amountMinor', 'note'));

        return ['status' => 'pending', 'refund_id' => 'rf_fake'.count($this->calls)];
    }

    // ---- test helpers ------------------------------------------------------------------------

    /** Mark a transaction as successfully paid (as a later verify call will say). @param array<string, mixed> $more */
    public function pay(string $reference, array $more = [], ?int $amountMinor = null): void
    {
        $base = $this->transactions[$reference] ?? ['reference' => $reference, 'amount_minor' => $amountMinor ?? 0, 'currency' => 'ZAR', 'customer_code' => 'CUS_fake', 'customer_email' => 'x@example.test', 'authorization_code' => 'AUTH_fake', 'plan_code' => null, 'metadata' => []];
        $this->transactions[$reference] = array_merge($base, ['status' => 'success', 'paid_at' => now()->toIso8601String()], $amountMinor !== null ? ['amount_minor' => $amountMinor] : [], $more);
    }

    public function called(string $method): int
    {
        return count(array_filter($this->calls, fn ($c) => $c[0] === $method));
    }

    /** @param array<string, mixed> $args */
    private function record(string $method, array $args): void
    {
        $this->calls[] = [$method, $args];

        if ($this->failNext !== null) {
            $e = $this->failNext;
            $this->failNext = null;

            throw $e;
        }
    }
}
