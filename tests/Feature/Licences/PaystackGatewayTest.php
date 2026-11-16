<?php

namespace Tests\Feature\Licences;

use App\Services\Paystack\HttpPaystackGateway;
use App\Services\Paystack\PaystackException;
use App\Services\Paystack\PlanRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakePaystackGateway;
use Tests\TestCase;

class PaystackGatewayTest extends TestCase
{
    use RefreshDatabase;

    private function http(string $key = 'sk_test_fake_value'): HttpPaystackGateway
    {
        config(['paystack.secret_key' => $key, 'paystack.base_url' => 'https://api.paystack.test']);

        return new HttpPaystackGateway;
    }

    public function test_no_key_means_the_feature_is_off_and_nothing_is_sent(): void
    {
        Http::fake();
        $gateway = $this->http('');

        $this->assertFalse($gateway->enabled());
        $this->expectException(PaystackException::class);
        try {
            $gateway->verifyTransaction('ref');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_plan_creation_sends_exact_amount_and_bearer_key(): void
    {
        Http::fake(['api.paystack.test/plan' => Http::response(['status' => true, 'message' => 'ok', 'data' => ['plan_code' => 'PLN_abc']], 200)]);

        $out = $this->http()->createPlan('Licences', 29700, 'ZAR', 'monthly');

        $this->assertSame('PLN_abc', $out['plan_code']);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer sk_test_fake_value') && $r['amount'] === 29700 && $r['currency'] === 'ZAR' && $r['interval'] === 'monthly');
    }

    public function test_initialize_with_plan_and_verify_map_the_documented_shapes(): void
    {
        Http::fake([
            'api.paystack.test/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/x', 'access_code' => 'ac', 'reference' => 'lic_1']], 200),
            'api.paystack.test/transaction/verify/lic_1' => Http::response(['status' => true, 'data' => [
                'status' => 'success', 'reference' => 'lic_1', 'amount' => 29700, 'currency' => 'ZAR', 'paid_at' => '2026-11-21T10:00:00.000Z',
                'customer' => ['customer_code' => 'CUS_1', 'email' => 'a@b.test'], 'authorization' => ['authorization_code' => 'AUTH_1'], 'plan_object' => ['plan_code' => 'PLN_abc'], 'metadata' => ['batch_uuid' => 'u'],
            ]], 200),
        ]);
        $gw = $this->http();

        $init = $gw->initializeTransaction('a@b.test', 29700, 'ZAR', 'lic_1', 'PLN_abc', 'https://infodot.test/back', ['batch_uuid' => 'u']);
        $this->assertSame('https://checkout.paystack.com/x', $init['authorization_url']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/transaction/initialize') && $r['plan'] === 'PLN_abc' && $r['callback_url'] === 'https://infodot.test/back');

        $v = $gw->verifyTransaction('lic_1');
        $this->assertSame(['success', 29700, 'ZAR', 'CUS_1', 'PLN_abc'], [$v['status'], $v['amount_minor'], $v['currency'], $v['customer_code'], $v['plan_code']]);
    }

    public function test_subscription_disable_and_refund_calls(): void
    {
        Http::fake([
            'api.paystack.test/subscription/disable' => Http::response(['status' => true, 'data' => []], 200),
            'api.paystack.test/subscription' => Http::response(['status' => true, 'data' => ['subscription_code' => 'SUB_1', 'email_token' => 't', 'status' => 'active']], 200),
            'api.paystack.test/refund' => Http::response(['status' => true, 'data' => ['status' => 'pending', 'id' => 99]], 200),
        ]);
        $gw = $this->http();

        $this->assertSame('SUB_1', $gw->createSubscription('CUS_1', 'PLN_abc', 'AUTH_1')['subscription_code']);
        $gw->disableSubscription('SUB_1', 't');
        $this->assertSame('99', $gw->refund('lic_1', 1000, 'note')['refund_id']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/subscription/disable') && $r['code'] === 'SUB_1' && $r['token'] === 't');
    }

    public function test_errors_become_exceptions_and_5xx_is_transient(): void
    {
        Http::fake(['api.paystack.test/*' => Http::sequence()->push(['status' => false, 'message' => 'Invalid key'], 401)->push('boom', 503)]);
        $gw = $this->http();

        try {
            $gw->verifyTransaction('a');
            $this->fail('expected');
        } catch (PaystackException $e) {
            $this->assertFalse($e->transient);
            $this->assertSame(401, $e->httpStatus);
            $this->assertStringNotContainsString('sk_test_fake_value', $e->getMessage());
        }

        try {
            $gw->verifyTransaction('a');
            $this->fail('expected');
        } catch (PaystackException $e) {
            $this->assertTrue($e->transient);
        }
    }

    public function test_the_same_amount_and_interval_reuse_one_plan_and_other_pairs_get_their_own(): void
    {
        $fake = new FakePaystackGateway;
        $plans = new PlanRegistry($fake);

        $a = $plans->forAmount(29700, 'zar', 'month');
        $b = $plans->forAmount(29700, 'ZAR', 'monthly');
        $c = $plans->forAmount(29700, 'ZAR', 'year');
        $d = $plans->forAmount(29800, 'ZAR', 'monthly');

        $this->assertTrue($a->is($b));
        $this->assertNotEquals($a->plan_code, $c->plan_code);
        $this->assertNotEquals($a->plan_code, $d->plan_code);
        $this->assertSame(3, $fake->called('createPlan'));
        $this->assertSame('annually', $c->interval);
    }

    public function test_bad_inputs_are_refused_before_paystack(): void
    {
        $fake = new FakePaystackGateway;
        $plans = new PlanRegistry($fake);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $plans->forAmount(0, 'ZAR', 'monthly');
        } finally {
            $this->assertSame(0, $fake->called('createPlan'));
        }
    }
}
