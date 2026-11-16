<?php

namespace Tests\Feature\Licences;

use App\Models\Licences\LicenceBatch;
use App\Models\Licences\LicenceOutboxEvent;
use App\Services\Licences\LicenceReconciler;
use App\Services\Licences\LicenceSignature;
use App\Services\Paystack\PaystackException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsLicences;
use Tests\TestCase;

class LicenceCancelTest extends TestCase
{
    use BuildsLicences;
    use RefreshDatabase;

    private const TEAM = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLicences();
        $this->configurePush();
        Http::fake(['infodot.test/api/finance/*' => Http::response(['status' => 'applied'])]);
    }

    /** @return array<string, mixed> */
    private function cancelBody(LicenceBatch $b, array $over = []): array
    {
        return $over + ['batch_uuid' => $b->batch_uuid, 'team_uuid' => $b->team_uuid, 'mode' => 'at_period_end'];
    }

    private function activeBatch(array $over = []): LicenceBatch
    {
        return $this->makeBatch($over + [
            'status' => 'active', 'first_paid_at' => now()->subDays(3), 'period_start' => now()->subDays(3), 'period_end' => now()->addDays(27),
            'paystack_subscription_code' => 'SUB_abc', 'subscription_email_token' => 'tok_abc',
        ]);
    }

    public function test_a_signed_cancel_disables_the_subscription_records_it_and_queues_the_cancelled_confirmation(): void
    {
        $b = $this->activeBatch();

        $this->signedPost('/api/licences/cancel', $this->cancelBody($b))->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('replayed', false);

        $call = collect($this->paystack->calls)->firstWhere(0, 'disableSubscription')[1];
        $this->assertSame(['subscriptionCode' => 'SUB_abc', 'emailToken' => 'tok_abc'], $call);
        $b->refresh();
        $this->assertSame('cancelled', $b->status);
        $this->assertNotNull($b->cancelled_at);
        $this->assertNotNull($b->cancel_requested_at);
        $this->assertSame(1, LicenceOutboxEvent::where('type', 'cancelled')->count());
    }

    public function test_the_emitted_event_has_the_shape_infodot_licence_batch_events_accepts(): void
    {
        $b = $this->activeBatch();
        $this->signedPost('/api/licences/cancel', $this->cancelBody($b))->assertOk();

        $sent = null;
        Http::assertSent(function (Request $r) use (&$sent) {
            $sent = json_decode($r->body(), true);

            return true;
        });

        $this->assertSame('licence_batch', $sent['kind']);
        $this->assertSame('cancelled', $sent['type']);
        $this->assertSame('lic:'.$b->batch_uuid.':cancelled', $sent['event_id']);
        $this->assertSame($b->batch_uuid, $sent['batch_uuid']);
        $this->assertSame($b->team_uuid, $sent['team_uuid']);
        foreach (['quantity', 'interval', 'period_start', 'period_end', 'amount_minor', 'currency', 'paystack_reference'] as $k) {
            $this->assertArrayHasKey($k, $sent);
        }
        $this->assertSame('ZAR', $sent['currency']);
        $this->assertNotSame('', $sent['paystack_reference']);
        // InfoDot's cancelled handler uses its own period_end and ignores the amount; the signature is the one InfoDot verifies.
        Http::assertSent(fn (Request $r) => $r->hasHeader('X-InfoDot-Signature') && $r->hasHeader('X-InfoDot-Timestamp'));
    }

    public function test_unsigned_badly_signed_and_stale_calls_are_refused_and_change_nothing(): void
    {
        $b = $this->activeBatch();
        $raw = json_encode($this->cancelBody($b));

        $this->postJson('/api/licences/cancel', $this->cancelBody($b))->assertStatus(401);
        $this->signedPost('/api/licences/cancel', $this->cancelBody($b), 'wrong-secret')->assertStatus(401);
        $this->signedPost('/api/licences/cancel', $this->cancelBody($b), null, time() - 301)->assertStatus(401);
        $this->call('POST', '/api/licences/cancel', [], [], [], $this->transformHeadersToServerVars([
            'X-InfoDot-Timestamp' => (string) time(), 'X-InfoDot-Signature' => LicenceSignature::sign($this->licenceSecret, time(), $raw.' '), 'Content-Type' => 'application/json',
        ]), $raw)->assertStatus(401);

        $this->assertSame(0, $this->paystack->called('disableSubscription'));
        $this->assertSame('active', $b->fresh()->status);
        $this->assertSame(0, LicenceOutboxEvent::count());
    }

    public function test_a_repeat_is_idempotent_one_disable_one_event(): void
    {
        $b = $this->activeBatch();
        $this->signedPost('/api/licences/cancel', $this->cancelBody($b))->assertOk();
        $this->signedPost('/api/licences/cancel', $this->cancelBody($b))->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('status', 'cancelled');

        $this->assertSame(1, $this->paystack->called('disableSubscription'));
        $this->assertSame(1, LicenceOutboxEvent::where('type', 'cancelled')->count());
    }

    public function test_the_paystack_disable_webhook_after_our_cancel_adds_no_second_event(): void
    {
        $this->configureWebhook();
        $b = $this->activeBatch();
        $this->signedPost('/api/licences/cancel', $this->cancelBody($b))->assertOk();

        $this->webhook(['event' => 'subscription.disable', 'data' => ['subscription_code' => 'SUB_abc']])->assertOk();

        $this->assertSame(1, LicenceOutboxEvent::where('type', 'cancelled')->count());
    }

    public function test_another_companys_batch_and_unknown_batches_are_refused_alike_and_untouched(): void
    {
        $b = $this->activeBatch();
        $other = '33333333-3333-4333-8333-333333333333';

        $a = $this->signedPost('/api/licences/cancel', $this->cancelBody($b, ['team_uuid' => $other]));
        $u = $this->signedPost('/api/licences/cancel', $this->cancelBody($b, ['batch_uuid' => '99999999-9999-4999-8999-999999999999']));

        $a->assertStatus(404)->assertJsonPath('error', 'unknown_batch');
        $u->assertStatus(404)->assertJsonPath('error', 'unknown_batch');
        $this->assertSame($a->getContent(), $u->getContent());
        $this->assertSame(0, $this->paystack->called('disableSubscription'));
        $this->assertSame('active', $b->fresh()->status);
        $this->assertNull($b->fresh()->cancel_requested_at);
    }

    public function test_a_gateway_failure_changes_nothing_and_a_retry_succeeds(): void
    {
        $b = $this->activeBatch();
        $this->paystack->failNext = new PaystackException('down', 503, true);

        $this->signedPost('/api/licences/cancel', $this->cancelBody($b))->assertStatus(502);
        $this->assertSame('active', $b->fresh()->status);
        $this->assertNull($b->fresh()->cancelled_at);
        $this->assertSame(0, LicenceOutboxEvent::count());

        $this->signedPost('/api/licences/cancel', $this->cancelBody($b))->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertSame(1, LicenceOutboxEvent::where('type', 'cancelled')->count());
    }

    public function test_a_batch_never_paid_is_closed_without_touching_paystack(): void
    {
        [$b] = $this->checkedOut();

        $this->signedPost('/api/licences/cancel', $this->cancelBody($b))->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertSame(0, $this->paystack->called('disableSubscription'));
        $this->assertSame(1, LicenceOutboxEvent::where('type', 'cancelled')->count());
    }

    public function test_a_paid_batch_whose_subscription_is_not_known_yet_is_not_claimed_cancelled(): void
    {
        $b = $this->makeBatch(['status' => 'active', 'first_paid_at' => now(), 'period_end' => now()->addDays(30)]);

        $this->signedPost('/api/licences/cancel', $this->cancelBody($b))->assertStatus(409)->assertJsonPath('error', 'subscription_not_ready');
        $b->refresh();
        $this->assertSame('active', $b->status);
        $this->assertTrue($b->needs_review);
        $this->assertNotNull($b->cancel_requested_at);
        $this->assertSame(0, LicenceOutboxEvent::count());
    }

    public function test_structure_mode_and_payments_off(): void
    {
        $b = $this->activeBatch();

        $this->signedPost('/api/licences/cancel', $this->cancelBody($b, ['mode' => 'now']))->assertStatus(422);
        $this->signedPost('/api/licences/cancel', ['batch_uuid' => $b->batch_uuid])->assertStatus(422);
        $this->paystack->on = false;
        $this->signedPost('/api/licences/cancel', $this->cancelBody($b))->assertStatus(503);
        $this->assertSame('active', $b->fresh()->status);
    }

    public function test_checkout_accepts_and_stores_the_buyer_address_and_vat_treatment_and_validates_them(): void
    {
        $address = ['line1' => '1 Long St', 'city' => 'Cape Town', 'postal_code' => '8001', 'country' => 'ZA'];
        $body = $this->checkoutBody(['customer' => ['name' => 'Acme Retail', 'email' => 'owner@acme.test', 'address' => $address], 'vat_treatment' => 'standard']);

        $this->signedPost('/api/licences/checkout', $body)->assertOk();
        $b = LicenceBatch::first();
        $this->assertSame('Cape Town', $b->customer_address['city']);
        $this->assertSame('standard', $b->vat_treatment);

        $this->signedPost('/api/licences/checkout', $this->checkoutBody(['batch_uuid' => '44444444-4444-4444-8444-444444444444', 'vat_treatment' => 'maybe']))->assertStatus(422);
        $this->signedPost('/api/licences/checkout', $this->checkoutBody(['batch_uuid' => '44444444-4444-4444-8444-444444444444', 'customer' => ['name' => 'A', 'email' => 'a@b.test', 'address' => 'a string']]))->assertStatus(422);
    }

    public function test_checkout_without_the_new_fields_still_works_and_a_replay_fills_only_empty_fields(): void
    {
        $this->signedPost('/api/licences/checkout', $this->checkoutBody())->assertOk();
        $this->assertNull(LicenceBatch::first()->customer_address);
        $this->assertNull(LicenceBatch::first()->vat_treatment);

        $with = $this->checkoutBody(['customer' => ['name' => 'Acme Retail', 'email' => 'owner@acme.test', 'address' => ['line1' => 'X', 'country' => 'ZA']], 'vat_treatment' => 'exempt']);
        $this->signedPost('/api/licences/checkout', $with)->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame('exempt', LicenceBatch::first()->vat_treatment);

        $again = $this->checkoutBody(['customer' => ['name' => 'Acme Retail', 'email' => 'owner@acme.test', 'address' => ['line1' => 'Y', 'country' => 'ZA']], 'vat_treatment' => 'standard']);
        $this->signedPost('/api/licences/checkout', $again)->assertOk();
        $this->assertSame('exempt', LicenceBatch::first()->vat_treatment);
        $this->assertSame('X', LicenceBatch::first()->customer_address['line1']);
    }

    public function test_the_reconciler_reads_infodots_real_reconcile_response_shape(): void
    {
        config(['licences.reconcile.infodot_url' => 'https://infodot.test/api/licences/reconcile/{team_uuid}', 'licences.push.secret' => $this->pushSecret]);
        $b = $this->activeBatch(['interval' => 'month', 'interval_raw' => 'month']);
        $row = fn (string $status) => ['team_uuid' => self::TEAM, 'batches' => [[
            'batch_uuid' => $b->batch_uuid, 'status' => $status, 'quantity' => 3, 'interval' => 'month', 'period_end' => $b->period_end->utc()->toIso8601String(), 'counts_now' => $status === 'active',
        ]]];
        Http::fake(['infodot.test/api/licences/reconcile/*' => Http::sequence()->push($row('active'))->push($row('ended'))]);

        $types = array_column(app(LicenceReconciler::class)->run(self::TEAM), 'type');
        $this->assertNotContains('infodot_unreachable', $types);
        $this->assertNotContains('infodot_missing_batch', $types);
        $this->assertNotContains('infodot_quantity_differs', $types);
        $this->assertNotContains('infodot_period_differs', $types);
        $this->assertNotContains('infodot_status_differs', $types);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === 'https://infodot.test/api/licences/reconcile/'.self::TEAM && $r->header('X-InfoDot-Signature') !== []);

        $this->assertContains('infodot_status_differs', array_column(app(LicenceReconciler::class)->run(self::TEAM), 'type'));
    }
}
