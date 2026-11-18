<?php

namespace Tests\Feature\Licences;

use App\Models\Licences\LicenceBatch;
use App\Models\Licences\LicenceOutboxEvent;
use App\Models\Licences\LicencePayment;
use App\Services\Licences\LicenceReconciler;
use App\Services\Licences\LicenceSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsLicences;
use Tests\TestCase;

class LicenceReconcileTest extends TestCase
{
    use BuildsLicences;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLicences();
        $this->configureWebhook();
        $this->configurePush();
    }

    private function paid(): LicenceBatch
    {
        [$batch, $ref] = $this->checkedOut();
        $this->paystack->pay($ref, ['plan_code' => 'PLN_fake1'], 29700);
        $this->webhook($this->chargeSuccess($ref))->assertOk();

        return $batch->fresh();
    }

    private function types(?string $team = null): array
    {
        return array_column(app(LicenceReconciler::class)->run($team), 'type');
    }

    public function test_a_clean_book_reports_nothing_apart_from_the_missing_invoice_when_no_seller_is_set(): void
    {
        Http::fake(['infodot.test/*' => Http::response(['status' => 'applied'], 200)]);
        $this->paid();

        $this->assertSame(['invoice_missing'], $this->types());
    }

    public function test_an_undelivered_confirmation_and_a_stuck_one_are_reported_and_nothing_is_changed(): void
    {
        Http::fake(['infodot.test/*' => Http::response('down', 503)]);
        $batch = $this->paid();
        $before = [LicenceOutboxEvent::first()->toArray(), $batch->toArray()];

        $types = $this->types();
        $this->assertContains('confirmation_undelivered', $types);

        LicenceOutboxEvent::query()->update(['status' => 'dead']);
        $this->assertContains('confirmation_stuck', $this->types());

        LicenceOutboxEvent::query()->delete();
        $this->assertContains('confirmation_missing', $this->types());

        $this->artisan('licences:reconcile')->assertExitCode(1);
        $this->assertSame($batch->toArray(), $batch->fresh()->toArray());
        $this->assertNotEmpty($before);
    }

    public function test_unmatched_money_flagged_batches_and_abandoned_checkouts_are_reported(): void
    {
        Http::fake(['infodot.test/*' => Http::response(['status' => 'applied'], 200)]);
        [$batch, $ref] = $this->checkedOut();
        $batch->forceFill(['created_at' => now()->subDays(2)])->save();
        $this->assertContains('abandoned_checkout', $this->types());

        $this->paystack->pay($ref, ['plan_code' => 'PLN_fake1'], 100);
        $this->webhook($this->chargeSuccess($ref))->assertOk();
        $types = $this->types();
        $this->assertContains('unmatched_payment', $types);
        $this->assertContains('needs_review', $types);
    }

    public function test_it_compares_with_infodots_answer_per_company_and_signs_the_read(): void
    {
        $answer = ['batches' => []];
        Http::fake(function ($r) use (&$answer) {
            return str_contains($r->url(), '/finance/licences/') ? Http::response($answer, 200) : Http::response(['status' => 'applied'], 200);
        });
        config(['licences.reconcile.infodot_url' => 'https://infodot.test/api/finance/licences/{team_uuid}']);
        $batch = $this->paid();
        LicencePayment::query()->update(['invoice_status' => 'issued']);

        $this->assertSame(['infodot_missing_batch'], $this->types());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/finance/licences/'.$batch->team_uuid) && $r->method() === 'GET'
            && $r->header('X-InfoDot-Signature')[0] === LicenceSignature::sign($this->pushSecret, (int) $r->header('X-InfoDot-Timestamp')[0], ''));

        $answer = ['batches' => [['batch_uuid' => $batch->batch_uuid, 'status' => 'active', 'quantity' => 2, 'period_end' => now()->addDays(40)->toIso8601String()]]];
        $types = $this->types();
        $this->assertContains('infodot_quantity_differs', $types);
        $this->assertContains('infodot_period_differs', $types);

        $answer = ['batches' => [['batch_uuid' => $batch->batch_uuid, 'status' => 'active', 'quantity' => 3, 'period_end' => $batch->period_end->toIso8601String()]]];
        $this->assertSame([], $this->types());
        $this->artisan('licences:reconcile')->assertExitCode(0);
    }

    public function test_an_unreachable_infodot_is_reported_not_fatal(): void
    {
        Http::fake(['infodot.test/api/finance/licences/*' => Http::response('x', 500), 'infodot.test/*' => Http::response(['status' => 'applied'], 200)]);
        config(['licences.reconcile.infodot_url' => 'https://infodot.test/api/finance/licences/{team_uuid}']);
        $this->paid();

        $this->assertContains('infodot_unreachable', $this->types());
    }

    public function test_the_entitlement_read_is_signed_company_specific_and_read_only(): void
    {
        Http::fake(['infodot.test/*' => Http::response(['status' => 'applied'], 200)]);
        $batch = $this->paid();
        $get = function (string $team, ?string $secret = null, ?int $ts = null) {
            $ts ??= time();

            return $this->call('GET', '/api/entitlements/'.$team, [], [], [], $this->transformHeadersToServerVars(['X-InfoDot-Timestamp' => (string) $ts, 'X-InfoDot-Signature' => LicenceSignature::sign($secret ?? $this->licenceSecret, $ts, ''), 'Accept' => 'application/json']));
        };

        $get($batch->team_uuid)->assertOk()->assertJsonPath('licensed_quantity', 3)->assertJsonPath('seat_limit', 4)->assertJsonPath('status', 'active')
            ->assertJsonPath('batches.0.batch_uuid', $batch->batch_uuid);
        $get('99999999-9999-4999-8999-999999999999')->assertStatus(404); // another company: nothing of ours leaks
        $get($batch->team_uuid, 'wrong')->assertStatus(401);
        $get($batch->team_uuid, null, time() - 400)->assertStatus(401);
        $this->call('GET', '/api/entitlements/'.$batch->team_uuid, [], [], [], ['HTTP_ACCEPT' => 'application/json'])->assertStatus(401);
    }
}
