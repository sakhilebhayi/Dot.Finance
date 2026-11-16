<?php

namespace Tests\Feature\Licences;

use App\Models\Licences\LicenceBatch;
use App\Models\Licences\LicenceOutboxEvent;
use App\Models\Licences\LicencePayment;
use App\Models\Licences\PaystackWebhookEvent;
use App\Services\Licences\PaystackSignature;
use App\Services\Paystack\PaystackException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsLicences;
use Tests\TestCase;

class PaystackWebhookTest extends TestCase
{
    use BuildsLicences;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLicences();
        $this->configureWebhook();
        $this->configurePush();
        Http::fake(['infodot.test/*' => Http::response(['status' => 'applied'], 200)]);
    }

    private function pay(string $reference, int $amount = 29700, array $more = []): void
    {
        $this->paystack->pay($reference, ['plan_code' => 'PLN_fake1'] + $more, $amount);
    }

    public function test_unsigned_wrongly_signed_and_unconfigured_deliveries_are_refused_and_touch_nothing(): void
    {
        [$batch, $ref] = $this->checkedOut();
        $this->pay($ref);
        $payload = $this->chargeSuccess($ref);

        $this->postJson('/api/paystack/webhook', $payload)->assertStatus(401);
        $this->webhook($payload, 'sk_test_someone_elses_key')->assertStatus(401);
        $raw = json_encode($payload);
        $this->call('POST', '/api/paystack/webhook', [], [], [], $this->transformHeadersToServerVars(['x-paystack-signature' => PaystackSignature::sign($raw, $this->paystackKey), 'Content-Type' => 'application/json']), $raw.' ')->assertStatus(401); // signed body differs from the raw body
        config(['paystack.secret_key' => '']);
        $this->webhook($payload, '')->assertStatus(401);

        $this->assertSame(0, PaystackWebhookEvent::count());
        $this->assertSame(0, LicencePayment::count());
        $this->assertSame('awaiting_payment', $batch->fresh()->status);
        $this->assertSame(0, $this->paystack->called('verifyTransaction'));
    }

    public function test_a_signature_made_with_sha256_instead_of_sha512_is_refused(): void
    {
        $raw = json_encode($this->chargeSuccess('x'));
        $sig = hash_hmac('sha256', $raw, $this->paystackKey);

        $this->call('POST', '/api/paystack/webhook', [], [], [], $this->transformHeadersToServerVars(['x-paystack-signature' => $sig, 'Content-Type' => 'application/json']), $raw)->assertStatus(401);
    }

    public function test_a_verified_first_payment_activates_the_batch_and_queues_paid_with_the_server_side_figures(): void
    {
        [$batch, $ref] = $this->checkedOut();
        $this->pay($ref);

        $this->webhook($this->chargeSuccess($ref))->assertOk()->assertJsonPath('status', 'processed');

        $batch->refresh();
        $this->assertSame('active', $batch->status);
        $this->assertSame('CUS_fake', $batch->paystack_customer_code);
        $this->assertTrue($batch->period_end->gt($batch->period_start));
        $this->assertSame(1, $this->paystack->called('verifyTransaction'));
        $event = LicenceOutboxEvent::sole();
        $this->assertSame('paid', $event->type);
        $this->assertSame($ref, $event->body()['paystack_reference']);
        $this->assertSame(29700, $event->body()['amount_minor']);
        $this->assertSame('sent', $event->status);
    }

    public function test_a_duplicate_delivery_is_a_no_op(): void
    {
        [, $ref] = $this->checkedOut();
        $this->pay($ref);

        $this->webhook($this->chargeSuccess($ref))->assertOk();
        $this->webhook($this->chargeSuccess($ref))->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertSame(1, LicencePayment::count());
        $this->assertSame(1, LicenceOutboxEvent::count());
        $this->assertSame(1, $this->paystack->called('verifyTransaction'));
    }

    public function test_the_body_alone_never_grants_value_an_unpaid_or_wrong_amount_transaction_is_not_applied(): void
    {
        [$batch, $ref] = $this->checkedOut();

        // Paystack says it was abandoned although the webhook body claims success
        $this->webhook($this->chargeSuccess($ref))->assertStatus(503);
        $this->assertSame('awaiting_payment', $batch->fresh()->status);
        $this->assertSame(0, LicenceOutboxEvent::count());

        // paid, but for a different amount than the batch
        $this->pay($ref, 100);
        $this->webhook($this->chargeSuccess($ref, 29700, ['id' => 2]))->assertOk();
        $this->assertSame('awaiting_payment', $batch->fresh()->status);
        $this->assertTrue($batch->fresh()->needs_review);
        $this->assertSame('unmatched', LicencePayment::sole()->status);
        $this->assertSame(0, LicenceOutboxEvent::count());
    }

    public function test_if_verification_is_down_the_delivery_is_retried_not_swallowed(): void
    {
        [$batch, $ref] = $this->checkedOut();
        $this->pay($ref);
        $this->paystack->failNext = new PaystackException('down', 503, true);

        $this->webhook($this->chargeSuccess($ref))->assertStatus(503);
        $this->assertSame('awaiting_payment', $batch->fresh()->status);
        $this->assertSame('received', PaystackWebhookEvent::sole()->status);

        $this->webhook($this->chargeSuccess($ref))->assertOk()->assertJsonPath('status', 'processed'); // Paystack's retry
        $this->assertSame('active', $batch->fresh()->status);
        $this->assertSame(1, PaystackWebhookEvent::count());
    }

    public function test_subscription_create_links_the_subscription_whichever_event_comes_first(): void
    {
        [$batch, $ref] = $this->checkedOut();
        $sub = ['event' => 'subscription.create', 'data' => ['subscription_code' => 'SUB_1', 'email_token' => 'tok_1', 'plan' => ['plan_code' => 'PLN_fake1'], 'customer' => ['customer_code' => 'CUS_fake', 'email' => 'OWNER@acme.test']]];

        $this->webhook($sub)->assertOk(); // before the charge: matched by email
        $this->assertSame('SUB_1', $batch->fresh()->paystack_subscription_code);

        $this->pay($ref);
        $this->webhook($this->chargeSuccess($ref))->assertOk();
        $this->assertSame('active', $batch->fresh()->status);
        $this->assertSame('tok_1', $batch->fresh()->subscription_email_token);
        $this->assertStringNotContainsString('tok_1', (string) \DB::table('licence_batches')->value('subscription_email_token'));
    }

    public function test_one_renewal_seen_as_invoice_update_and_charge_success_is_granted_once(): void
    {
        [$batch, $ref] = $this->checkedOut();
        $this->pay($ref);
        $this->webhook($this->chargeSuccess($ref))->assertOk();
        $this->webhook(['event' => 'subscription.create', 'data' => ['subscription_code' => 'SUB_1', 'email_token' => 't', 'plan' => ['plan_code' => 'PLN_fake1'], 'customer' => ['customer_code' => 'CUS_fake']]])->assertOk();
        $firstEnd = $batch->fresh()->period_end;

        $renewalRef = 'renew_ref_1';
        $this->pay($renewalRef, 29700, ['customer_code' => 'CUS_fake']);
        $invoice = ['event' => 'invoice.update', 'data' => ['invoice_code' => 'INV_1', 'paid' => true, 'status' => 'success', 'subscription' => ['subscription_code' => 'SUB_1'], 'transaction' => ['reference' => $renewalRef], 'customer' => ['customer_code' => 'CUS_fake']]];
        $charge = $this->chargeSuccess($renewalRef, 29700, ['plan' => ['plan_code' => 'PLN_fake1']]);

        $this->webhook($invoice)->assertOk()->assertJsonPath('status', 'processed');
        $this->webhook($charge)->assertOk()->assertJsonPath('status', 'ignored');

        $this->assertSame(2, LicencePayment::count());
        $renewed = LicenceOutboxEvent::where('type', 'renewed')->get();
        $this->assertCount(1, $renewed);
        $this->assertTrue($batch->fresh()->period_end->gt($firstEnd));
        $this->assertEquals($firstEnd->toDateTimeString(), $batch->fresh()->period_start->toDateTimeString());
        $this->assertSame(1, $this->paystack->called('verifyTransaction') - 1, 'the renewal was verified once; the second event found the payment recorded');
    }

    public function test_renewal_via_charge_success_alone_matches_by_customer_and_plan(): void
    {
        [$batch, $ref] = $this->checkedOut();
        $this->pay($ref);
        $this->webhook($this->chargeSuccess($ref))->assertOk();

        $this->pay('renew_2', 29700, ['customer_code' => 'CUS_fake']);
        $this->webhook($this->chargeSuccess('renew_2', 29700, ['plan' => ['plan_code' => 'PLN_fake1']]))->assertOk()->assertJsonPath('status', 'processed');

        $this->assertSame(1, LicenceOutboxEvent::where('type', 'renewed')->count());
        $this->assertSame('active', $batch->fresh()->status);
    }

    public function test_a_payment_that_is_not_ours_is_ignored(): void
    {
        $this->paystack->pay('other_product', ['plan_code' => null, 'customer_code' => 'CUS_other'], 5000);

        $this->webhook($this->chargeSuccess('other_product', 5000))->assertOk()->assertJsonPath('status', 'ignored');
        $this->assertSame(0, LicencePayment::count());
    }

    public function test_a_renewal_that_fits_two_equal_batches_is_recorded_unmatched_not_guessed(): void
    {
        [$a, $refA] = $this->checkedOut();
        $b2 = ['batch_uuid' => '77777777-7777-4777-8777-777777777777'];
        [$b, $refB] = $this->checkedOut($b2);
        foreach ([$refA, $refB] as $r) {
            $this->pay($r);
            $this->webhook($this->chargeSuccess($r))->assertOk();
        }
        // identical period ends: ambiguous
        LicenceBatch::query()->update(['period_end' => now()->addDays(3)]);

        $this->pay('renew_x', 29700, ['customer_code' => 'CUS_fake']);
        $this->webhook($this->chargeSuccess('renew_x', 29700, ['plan' => ['plan_code' => 'PLN_fake1']]))->assertOk();

        $this->assertSame('unmatched', LicencePayment::where('paystack_reference', 'renew_x')->sole()->status);
        $this->assertTrue($a->fresh()->needs_review && $b->fresh()->needs_review);
        $this->assertSame(0, LicenceOutboxEvent::where('type', 'renewed')->count());
    }

    public function test_payment_failed_marks_past_due_and_tells_infodot_once(): void
    {
        [$batch, $ref] = $this->checkedOut();
        $this->pay($ref);
        $this->webhook($this->chargeSuccess($ref))->assertOk();
        $this->webhook(['event' => 'subscription.create', 'data' => ['subscription_code' => 'SUB_1', 'plan' => ['plan_code' => 'PLN_fake1'], 'customer' => ['customer_code' => 'CUS_fake']]])->assertOk();

        $failed = ['event' => 'invoice.payment_failed', 'data' => ['invoice_code' => 'INV_9', 'subscription' => ['subscription_code' => 'SUB_1'], 'customer' => ['customer_code' => 'CUS_fake']]];
        $this->webhook($failed)->assertOk();
        $this->webhook($failed)->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertSame('past_due', $batch->fresh()->status);
        $this->assertSame(1, LicenceOutboxEvent::where('type', 'payment_failed')->count());

        // a later successful renewal brings it back to active
        $this->pay('renew_ok', 29700, ['customer_code' => 'CUS_fake']);
        $this->webhook($this->chargeSuccess('renew_ok', 29700, ['plan' => ['plan_code' => 'PLN_fake1'], 'subscription' => ['subscription_code' => 'SUB_1']]))->assertOk();
        $this->assertSame('active', $batch->fresh()->status);
    }

    public function test_disable_and_not_renew_send_cancelled_once_with_the_period_end(): void
    {
        [$batch, $ref] = $this->checkedOut();
        $this->pay($ref);
        $this->webhook($this->chargeSuccess($ref))->assertOk();
        $this->webhook(['event' => 'subscription.create', 'data' => ['subscription_code' => 'SUB_1', 'plan' => ['plan_code' => 'PLN_fake1'], 'customer' => ['customer_code' => 'CUS_fake']]])->assertOk();

        $this->webhook(['event' => 'subscription.not_renew', 'data' => ['subscription_code' => 'SUB_1', 'status' => 'non-renewing']])->assertOk();
        $this->webhook(['event' => 'subscription.disable', 'data' => ['subscription_code' => 'SUB_1', 'status' => 'complete']])->assertOk();

        $this->assertSame('cancelled', $batch->fresh()->status);
        $events = LicenceOutboxEvent::where('type', 'cancelled')->get();
        $this->assertCount(1, $events);
        $this->assertSame($batch->fresh()->period_end->utc()->toIso8601String(), $events[0]->body()['period_end']);
    }

    public function test_a_processed_refund_marks_the_payment_stops_renewals_and_tells_infodot_once(): void
    {
        [$batch, $ref] = $this->checkedOut();
        $this->pay($ref);
        $this->webhook($this->chargeSuccess($ref))->assertOk();
        $this->webhook(['event' => 'subscription.create', 'data' => ['subscription_code' => 'SUB_1', 'email_token' => 'tok', 'plan' => ['plan_code' => 'PLN_fake1'], 'customer' => ['customer_code' => 'CUS_fake']]])->assertOk();

        $refund = ['event' => 'refund.processed', 'data' => ['id' => 555, 'transaction_reference' => $ref, 'amount' => 29700, 'status' => 'processed']];
        $this->webhook($refund)->assertOk();
        $this->webhook($refund)->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertSame('refunded', LicencePayment::first()->status);
        $this->assertSame('refunded', $batch->fresh()->status);
        $this->assertSame(1, LicenceOutboxEvent::where('type', 'refunded')->count());
        $this->assertSame(1, $this->paystack->called('disableSubscription'));
        $this->assertSame(29700, LicenceOutboxEvent::where('type', 'refunded')->sole()->body()['amount_minor']);
    }

    public function test_events_and_refunds_for_unknown_things_are_ignored_quietly(): void
    {
        $this->webhook(['event' => 'refund.processed', 'data' => ['id' => 1, 'transaction_reference' => 'nope']])->assertOk()->assertJsonPath('status', 'ignored');
        $this->webhook(['event' => 'transfer.success', 'data' => ['reference' => 'x']])->assertOk()->assertJsonPath('status', 'ignored');
        $this->webhook(['event' => 'subscription.create', 'data' => ['subscription_code' => 'SUB_9', 'plan' => ['plan_code' => 'PLN_other'], 'customer' => ['customer_code' => 'CUS_x']]])->assertOk()->assertJsonPath('status', 'ignored');
        $this->assertSame(0, LicenceOutboxEvent::count());
    }

    public function test_malformed_signed_bodies_get_400(): void
    {
        $this->webhook([], null, '{"event":1}')->assertStatus(400);
        $this->webhook([], null, 'not json')->assertStatus(400);
    }

    public function test_no_secret_in_logs_or_responses(): void
    {
        [, $ref] = $this->checkedOut();
        $this->pay($ref);
        $res = $this->webhook($this->chargeSuccess($ref));

        $this->assertStringNotContainsString($this->paystackKey, $res->getContent());
        $this->assertStringNotContainsString('AUTH_fake', (string) \DB::table('licence_batches')->value('authorization_code'));
    }
}
