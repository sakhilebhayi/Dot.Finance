<?php

namespace Tests\Feature\Licences;

use App\Jobs\PushLicenceEvent;
use App\Models\Licences\LicenceOutboxEvent;
use App\Services\Licences\LicenceOutbox;
use App\Services\Licences\LicenceSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsLicences;
use Tests\TestCase;

class LicenceOutboxTest extends TestCase
{
    use BuildsLicences;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLicences();
        $this->configurePush();
    }

    private function emit(string $eventId = 'lic:b1:paid:ref1', string $type = 'paid'): LicenceOutboxEvent
    {
        $batch = $this->makeBatch(['period_start' => now()->startOfSecond(), 'period_end' => now()->addMonth()->startOfSecond()]);

        return app(LicenceOutbox::class)->emit($batch, $type, $eventId, ['reference' => 'ref1']);
    }

    public function test_the_push_carries_the_contract_fields_and_a_valid_signature(): void
    {
        Http::fake(['infodot.test/*' => Http::response(['status' => 'applied'], 200)]);
        $event = $this->emit();

        $this->assertSame('sent', $event->fresh()->status); // sync queue: delivered at once

        Http::assertSent(function (Request $r) {
            $b = $r->data();
            $ts = (int) $r->header('X-InfoDot-Timestamp')[0];

            return $r->url() === 'https://infodot.test/api/finance/platform-subscription-events'
                && $b['kind'] === 'licence_batch' && $b['type'] === 'paid' && $b['event_id'] === 'lic:b1:paid:ref1'
                && $b['quantity'] === 3 && $b['interval'] === 'monthly' && $b['amount_minor'] === 29700 && $b['currency'] === 'ZAR' && $b['paystack_reference'] === 'ref1'
                && isset($b['batch_uuid'], $b['team_uuid'], $b['period_start'], $b['period_end'])
                && $r->header('X-InfoDot-Signature')[0] === LicenceSignature::sign($this->pushSecret, $ts, $r->body())
                && abs(time() - $ts) < 5;
        });
        $this->assertSame('applied', $event->fresh()->infodot_answer);
    }

    public function test_the_same_event_id_is_stored_and_sent_once(): void
    {
        Http::fake(['infodot.test/*' => Http::response(['status' => 'applied'], 200)]);
        $batch = $this->makeBatch();
        $outbox = app(LicenceOutbox::class);

        $outbox->emit($batch, 'paid', 'e1', ['reference' => 'r']);
        $outbox->emit($batch, 'paid', 'e1', ['reference' => 'r']);

        $this->assertSame(1, LicenceOutboxEvent::count());
        Http::assertSentCount(1);
    }

    public function test_an_outage_keeps_the_event_pending_with_backoff_and_it_goes_when_infodot_is_back(): void
    {
        Http::fake(['infodot.test/*' => Http::sequence()->push('down', 503)->push(['status' => 'duplicate'], 200)]);
        $event = $this->emit();

        $event = $event->fresh();
        $this->assertSame('pending', $event->status);
        $this->assertSame(1, $event->attempts);
        $this->assertSame(503, $event->last_http_status);
        $this->assertTrue($event->next_attempt_at->isFuture());

        $this->assertSame(0, app(LicenceOutbox::class)->sweep(), 'not due yet');
        $this->travel(2)->minutes();
        $this->assertSame(1, app(LicenceOutbox::class)->sweep());
        $this->assertSame('sent', $event->fresh()->status);
        $this->assertSame('duplicate', $event->fresh()->infodot_answer);
    }

    public function test_after_the_attempt_limit_the_event_is_dead_but_never_deleted_and_can_be_requeued(): void
    {
        Http::fake(['infodot.test/*' => Http::sequence()->push('down', 500)->push('down', 500)->push('down', 500)->push(['status' => 'applied'], 200)]);
        $event = $this->emit();
        for ($i = 0; $i < 3; $i++) {
            $this->travel(7)->hours();
            app(LicenceOutbox::class)->sweep();
        }

        $this->assertSame('dead', $event->fresh()->status);
        $this->artisan('licences:push-outbox', ['--status' => true])->assertExitCode(1);

        $this->artisan('licences:push-outbox', ['--requeue' => true])->assertExitCode(0);
        $this->assertSame('sent', $event->fresh()->status);
        $this->assertSame(1, LicenceOutboxEvent::count());
    }

    public function test_a_refused_body_is_parked_for_a_person_not_retried(): void
    {
        Http::fake(['infodot.test/*' => Http::response(['error' => 'bad_request'], 422)]);
        $event = $this->emit();

        $this->assertSame('rejected', $event->fresh()->status);
        $this->assertNull($event->fresh()->next_attempt_at);
        Http::assertSentCount(1);
    }

    public function test_a_401_is_retried_because_a_secret_can_be_fixed(): void
    {
        Http::fake(['infodot.test/*' => Http::response(['error' => 'invalid_request'], 401)]);

        $this->assertSame('pending', $this->emit()->fresh()->status);
    }

    public function test_without_push_settings_nothing_is_sent_and_the_row_waits(): void
    {
        config(['licences.push.url' => '']);
        Http::fake();
        $event = $this->emit();

        Http::assertNothingSent();
        $this->assertSame('pending', $event->fresh()->status);
        $this->assertSame('push not configured', $event->fresh()->last_error);
    }

    public function test_a_duplicate_delivery_attempt_does_not_double_send(): void
    {
        Http::fake(['infodot.test/*' => Http::response(['status' => 'applied'], 200)]);
        $event = $this->emit();

        app(LicenceOutbox::class)->deliver($event->id);
        app(LicenceOutbox::class)->deliver($event->id);

        Http::assertSentCount(1);
    }

    public function test_emit_dispatches_the_job_after_commit_and_rejects_unknown_types(): void
    {
        Bus::fake();
        $batch = $this->makeBatch();
        app(LicenceOutbox::class)->emit($batch, 'paid', 'e9', ['reference' => 'r']);

        Bus::assertDispatched(PushLicenceEvent::class);
        $this->expectException(\InvalidArgumentException::class);
        app(LicenceOutbox::class)->emit($batch, 'granted', 'e10', ['reference' => 'r']);
    }
}
