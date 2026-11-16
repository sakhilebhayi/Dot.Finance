<?php

namespace Tests\Concerns;

use App\Models\Licences\LicenceBatch;
use App\Services\Licences\LicenceSignature;
use App\Services\Licences\PaystackSignature;
use App\Services\Paystack\PaystackGateway;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakePaystackGateway;

/** Signed InfoDot calls into Finance and a fake Paystack, for the R-53 licence tests. Every secret here is a fake. */
trait BuildsLicences
{
    protected string $licenceSecret = 'fake-licence-secret-for-tests';

    protected FakePaystackGateway $paystack;

    protected function setUpLicences(): void
    {
        config(['licences.infodot_secret' => $this->licenceSecret]);
        $this->paystack = new FakePaystackGateway;
        $this->app->instance(PaystackGateway::class, $this->paystack);
    }

    /** @return array<string, mixed> */
    protected function checkoutBody(array $over = []): array
    {
        return $over + [
            'batch_uuid' => '11111111-1111-4111-8111-111111111111', 'team_uuid' => '22222222-2222-4222-8222-222222222222',
            'quantity' => 3, 'interval' => 'monthly', 'total_minor' => 29700, 'currency' => 'ZAR',
            'customer' => ['name' => 'Acme Retail', 'email' => 'owner@acme.test'],
        ];
    }

    /** @param  array<string, mixed>  $body */
    protected function signedPost(string $uri, array $body, ?string $secret = null, ?int $timestamp = null, string $family = 'X-InfoDot'): TestResponse
    {
        $raw = json_encode($body);
        $ts = $timestamp ?? time();

        return $this->call('POST', $uri, [], [], [], $this->transformHeadersToServerVars([
            $family.'-Timestamp' => (string) $ts, $family.'-Signature' => LicenceSignature::sign($secret ?? $this->licenceSecret, $ts, $raw), 'Content-Type' => 'application/json', 'Accept' => 'application/json',
        ]), $raw);
    }

    protected string $pushSecret = 'fake-push-secret-for-tests';

    protected function configurePush(): void
    {
        config(['licences.push.url' => 'https://infodot.test/api/finance/platform-subscription-events', 'licences.push.secret' => $this->pushSecret, 'licences.push.max_attempts' => 3]);
    }

    protected function makeBatch(array $over = []): LicenceBatch
    {
        return LicenceBatch::query()->create($over + [
            'batch_uuid' => (string) Str::uuid(), 'team_uuid' => '22222222-2222-4222-8222-222222222222', 'quantity' => 3, 'interval' => 'monthly', 'interval_raw' => 'monthly',
            'total_minor' => 29700, 'currency' => 'ZAR', 'customer_name' => 'Acme Retail', 'customer_email' => 'owner@acme.test', 'status' => 'awaiting_payment',
            'paystack_reference' => 'lic_'.bin2hex(random_bytes(6)),
        ]);
    }

    protected string $paystackKey = 'sk_test_fake_key_for_tests';

    protected function configureWebhook(): void
    {
        config(['paystack.secret_key' => $this->paystackKey]);
    }

    /** @param  array<string, mixed>  $payload */
    protected function webhook(array $payload, ?string $key = null, ?string $rawOverride = null): TestResponse
    {
        $raw = $rawOverride ?? json_encode($payload);

        return $this->call('POST', '/api/paystack/webhook', [], [], [], $this->transformHeadersToServerVars([
            'x-paystack-signature' => PaystackSignature::sign($raw, $key ?? $this->paystackKey), 'Content-Type' => 'application/json', 'Accept' => 'application/json',
        ]), $raw);
    }

    /** A batch that went through checkout, ready for its first charge. @return array{0: \App\Models\Licences\LicenceBatch, 1: string} */
    protected function checkedOut(array $over = []): array
    {
        $this->signedPost('/api/licences/checkout', $this->checkoutBody($over))->assertOk();
        $batch = LicenceBatch::query()->where('batch_uuid', $this->checkoutBody($over)['batch_uuid'])->firstOrFail();

        return [$batch, (string) $batch->paystack_reference];
    }

    /** @return array<string, mixed> */
    protected function chargeSuccess(string $reference, int $amount = 29700, array $data = []): array
    {
        return ['event' => 'charge.success', 'data' => $data + ['reference' => $reference, 'amount' => $amount, 'currency' => 'ZAR', 'status' => 'success', 'customer' => ['customer_code' => 'CUS_fake', 'email' => 'owner@acme.test']]];
    }
}
