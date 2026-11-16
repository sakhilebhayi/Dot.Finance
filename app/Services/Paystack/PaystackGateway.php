<?php

namespace App\Services\Paystack;

/**
 * The one door to Paystack (R-53). All amounts are integers in minor units (ZAR cents); every method either returns the
 * documented shape or throws PaystackException. The real adapter is HttpPaystackGateway; tests use FakePaystackGateway.
 */
interface PaystackGateway
{
    /** False when no secret key is configured: the licence billing feature is off. */
    public function enabled(): bool;

    /**
     * Create a plan. Paystack plan amounts are fixed, so callers go through PlanRegistry, which creates one plan per
     * (amount, currency, interval) and reuses it.
     *
     * @return array{plan_code: string}
     */
    public function createPlan(string $name, int $amountMinor, string $currency, string $interval): array;

    /**
     * Start a hosted checkout. With a plan, Paystack creates the subscription when the first charge succeeds.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{authorization_url: string, access_code: string, reference: string}
     */
    public function initializeTransaction(string $email, int $amountMinor, string $currency, string $reference, ?string $planCode, ?string $callbackUrl, array $metadata = []): array;

    /**
     * Subscribe an existing customer (with a reusable authorization) to a plan.
     *
     * @return array{subscription_code: string, email_token: ?string, status: string}
     */
    public function createSubscription(string $customerCode, string $planCode, ?string $authorizationCode = null, ?string $startDate = null): array;

    /** Both the code and the email token are required by Paystack to disable a subscription. */
    public function disableSubscription(string $subscriptionCode, string $emailToken): void;

    /**
     * Server-side truth about one transaction.
     *
     * @return array{status: string, reference: string, amount_minor: int, currency: string, paid_at: ?string, customer_code: ?string, customer_email: ?string, authorization_code: ?string, plan_code: ?string, metadata: array<string, mixed>}
     */
    public function verifyTransaction(string $reference): array;

    /**
     * Refund a transaction, fully (null amount) or in part.
     *
     * @return array{status: string, refund_id: ?string}
     */
    public function refund(string $transactionReference, ?int $amountMinor = null, ?string $note = null): array;
}
