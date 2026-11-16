<?php

namespace Tests\Concerns;

use App\Services\Licences\LicenceSignature;
use App\Services\Paystack\PaystackGateway;
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
}
