<?php

namespace Tests\Feature\Licences;

use App\Models\Licences\LicenceBatch;
use App\Services\Paystack\PaystackException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLicences;
use Tests\TestCase;

class LicenceCheckoutTest extends TestCase
{
    use BuildsLicences;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLicences();
    }

    public function test_a_signed_request_creates_a_plan_a_batch_and_returns_the_checkout_url(): void
    {
        $res = $this->signedPost('/api/licences/checkout', $this->checkoutBody());

        $res->assertOk()->assertJsonPath('status', 'awaiting_payment')->assertJsonPath('replayed', false);
        $this->assertStringStartsWith('https://checkout.paystack.test/lic_', $res->json('checkout_url'));

        $init = collect($this->paystack->calls)->firstWhere(0, 'initializeTransaction')[1];
        $this->assertSame(29700, $init['amountMinor']);
        $this->assertSame('PLN_fake1', $init['planCode']);
        $this->assertSame('owner@acme.test', $init['email']);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $init['metadata']['batch_uuid']);
        $this->assertSame(1, LicenceBatch::count());
    }

    public function test_the_same_batch_again_replays_without_a_second_paystack_call(): void
    {
        $first = $this->signedPost('/api/licences/checkout', $this->checkoutBody())->json('checkout_url');
        $second = $this->signedPost('/api/licences/checkout', $this->checkoutBody());

        $second->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('checkout_url', $first);
        $this->assertSame(1, $this->paystack->called('initializeTransaction'));
        $this->assertSame(1, LicenceBatch::count());
    }

    public function test_the_same_batch_uuid_with_different_terms_is_a_conflict_and_changes_nothing(): void
    {
        $this->signedPost('/api/licences/checkout', $this->checkoutBody())->assertOk();

        $this->signedPost('/api/licences/checkout', $this->checkoutBody(['total_minor' => 100]))->assertStatus(409);
        $this->signedPost('/api/licences/checkout', $this->checkoutBody(['quantity' => 9]))->assertStatus(409);
        $this->signedPost('/api/licences/checkout', $this->checkoutBody(['team_uuid' => '33333333-3333-4333-8333-333333333333']))->assertStatus(409);

        $this->assertSame(29700, LicenceBatch::first()->total_minor);
        $this->assertSame(1, $this->paystack->called('initializeTransaction'));
    }

    public function test_equal_amounts_share_one_plan_and_different_batches_get_their_own_checkout(): void
    {
        $this->signedPost('/api/licences/checkout', $this->checkoutBody())->assertOk();
        $this->signedPost('/api/licences/checkout', $this->checkoutBody(['batch_uuid' => '44444444-4444-4444-8444-444444444444', 'team_uuid' => '55555555-5555-4555-8555-555555555555']))->assertOk();
        $this->signedPost('/api/licences/checkout', $this->checkoutBody(['batch_uuid' => '66666666-6666-4666-8666-666666666666', 'interval' => 'annual', 'total_minor' => 297000]))->assertOk();

        $this->assertSame(2, $this->paystack->called('createPlan'));
        $this->assertSame(3, LicenceBatch::count());
        $this->assertSame('annually', LicenceBatch::where('total_minor', 297000)->first()->interval);
    }

    public function test_unsigned_stale_wrongly_signed_or_unconfigured_calls_get_the_same_401(): void
    {
        $this->postJson('/api/licences/checkout', $this->checkoutBody())->assertStatus(401);
        $this->signedPost('/api/licences/checkout', $this->checkoutBody(), 'a-different-secret')->assertStatus(401);
        $this->signedPost('/api/licences/checkout', $this->checkoutBody(), null, time() - 301)->assertStatus(401);
        config(['licences.infodot_secret' => '']);
        $this->signedPost('/api/licences/checkout', $this->checkoutBody(), '')->assertStatus(401);

        $this->assertSame(0, LicenceBatch::count());
        $this->assertSame([], $this->paystack->calls);
    }

    public function test_the_contract_header_family_is_accepted_too(): void
    {
        $this->signedPost('/api/licences/checkout', $this->checkoutBody(), null, null, 'X-Dot')->assertOk();
    }

    public function test_bad_bodies_are_refused_and_no_amount_can_come_from_anywhere_else(): void
    {
        foreach ([
            ['quantity' => 0], ['quantity' => 'x'], ['total_minor' => 0], ['total_minor' => 12.5], ['currency' => 'USD'], ['interval' => 'weekly'],
            ['batch_uuid' => 'not-a-uuid'], ['customer' => ['name' => 'A', 'email' => 'nope']],
        ] as $over) {
            $this->signedPost('/api/licences/checkout', $this->checkoutBody($over))->assertStatus(422);
        }
        $this->assertSame(0, LicenceBatch::count());
    }

    public function test_a_query_string_amount_is_ignored(): void
    {
        $this->signedPost('/api/licences/checkout?total_minor=1&amount=1', $this->checkoutBody())->assertOk();

        $this->assertSame(29700, LicenceBatch::first()->total_minor);
    }

    public function test_without_a_paystack_key_the_feature_is_off(): void
    {
        $this->paystack->on = false;

        $this->signedPost('/api/licences/checkout', $this->checkoutBody())->assertStatus(503);
        $this->assertSame(0, LicenceBatch::count());
    }

    public function test_paystack_failure_leaves_the_batch_pending_and_a_retry_uses_a_fresh_reference(): void
    {
        $this->paystack->failNext = null;
        $this->signedPost('/api/licences/checkout', $this->checkoutBody())->assertOk(); // plan created, init ok -> baseline
        LicenceBatch::query()->update(['checkout_url' => null, 'status' => 'pending']);
        $this->paystack->calls = [];
        $this->paystack->failNext = new PaystackException('down', 503, true);
        // the plan is cached, so the next recorded call is initializeTransaction
        $this->signedPost('/api/licences/checkout', $this->checkoutBody())->assertStatus(502);
        $this->assertSame('pending', LicenceBatch::first()->status);

        $ok = $this->signedPost('/api/licences/checkout', $this->checkoutBody());
        $ok->assertOk();
        $this->assertStringEndsWith('_3', $ok->json('checkout_url'));
        $this->assertSame(3, LicenceBatch::first()->init_attempts);
    }

    public function test_the_batch_never_exposes_its_secrets_in_json(): void
    {
        $this->signedPost('/api/licences/checkout', $this->checkoutBody())->assertOk();
        $batch = LicenceBatch::first();
        $batch->forceFill(['authorization_code' => 'AUTH_secret', 'subscription_email_token' => 'tok_secret'])->save();

        $this->assertStringNotContainsString('AUTH_secret', $batch->fresh()->toJson());
        $this->assertDatabaseMissing('licence_batches', ['authorization_code' => 'AUTH_secret']);
    }
}
