<?php

namespace Tests\Feature\Integrations;

use App\Jobs\SendAnalyticsSnapshot;
use App\Services\Integrations\Analytics\AnalyticsClient;
use App\Services\Integrations\Analytics\SnapshotPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsIntelFixtures;
use Tests\TestCase;

class AnalyticsPublisherTest extends TestCase
{
    use BuildsIntelFixtures;
    use RefreshDatabase;

    private const URL = 'https://analytics.test/api/ingest/snapshots';

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixTime();
        $this->b = $this->business();
        $this->spend($this->b['bank'], $this->b['sales'], 500000, '2026-11-05', 'Secret customer invoice', 'income');
        $this->spend($this->b['bank'], $this->b['food'], 120000, '2026-11-06', 'Private supplier payment');
        $this->spend($this->b['bank'], $this->b['food'], 99999, '2026-10-06', 'Last month, not counted');
        Cache::flush();
    }

    private function enable(): void
    {
        config(['integrations.analytics.url' => self::URL, 'integrations.analytics.secret' => 'analytics-secret']);
    }

    public function test_it_does_nothing_until_the_url_and_secret_are_both_set(): void
    {
        Http::fake();
        Queue::fake();

        $this->artisan('finance:publish-metrics')->expectsOutputToContain('not configured')->assertSuccessful();

        config(['integrations.analytics.url' => self::URL]);
        $this->artisan('finance:publish-metrics')->expectsOutputToContain('not configured')->assertSuccessful();

        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertCount(0, app(SnapshotPublisher::class)->publishAll());
    }

    public function test_the_snapshot_is_aggregate_only_and_follows_the_analytics_contract(): void
    {
        $payload = app(SnapshotPublisher::class)->payload($this->b['context']);

        $this->assertSame('finance', $payload['platform']);
        $this->assertSame(strtolower($this->b['team']->infodot_team_uuid), $payload['company_uuid']);
        $this->assertSame('2026-11-18T00:00:00Z', $payload['taken_at']);
        $this->assertSame('day', $payload['period']);

        $m = $payload['metrics'];
        $this->assertSame(500000, $m['income_mtd_cents']);
        $this->assertSame(120000, $m['expense_mtd_cents']);
        $this->assertSame(2, $m['transactions_mtd']);
        $this->assertSame(1, $m['accounts']);
        $this->assertSame('ZAR', $m['currency']);

        // Only keys of the contract; every value a non-negative whole number except the currency code.
        $this->assertSame([], array_diff(array_keys($m), ['income_mtd_cents', 'expense_mtd_cents', 'transactions_mtd', 'accounts', 'open_findings', 'pending_approvals', 'receivables_cents', 'currency']));
        foreach ($m as $key => $value) {
            $key === 'currency' ? $this->assertMatchesRegularExpression('/^[A-Z]{3}$/', $value) : $this->assertTrue(is_int($value) && $value >= 0, $key);
        }

        // Nothing that identifies an entry, a person or an account leaves.
        $json = json_encode($payload);
        foreach (['Secret customer invoice', 'Private supplier payment', 'Cheque', 'Food', $this->b['owner']->email, $this->b['owner']->name] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
    }

    public function test_a_personal_ledger_is_never_published(): void
    {
        $user = $this->user();
        $account = \App\Models\Account::factory()->create(['user_id' => $user->id, 'team_id' => null]);
        $this->spend($account, null, 100, '2026-11-05');
        $this->enable();

        $payloads = app(SnapshotPublisher::class)->publishAll(dryRun: true);

        $this->assertCount(1, $payloads);
        $this->assertSame(strtolower($this->b['team']->infodot_team_uuid), $payloads->first()['company_uuid']);
        $this->assertNull(app(SnapshotPublisher::class)->payload(\App\Support\Ledger\LedgerContext::personal($user)));
    }

    public function test_dry_run_prints_the_payloads_and_sends_nothing(): void
    {
        Http::fake();
        Queue::fake();

        $this->artisan('finance:publish-metrics --dry-run')->expectsOutputToContain('"platform": "finance"')->expectsOutputToContain('would not be sent')->assertSuccessful();

        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_when_configured_one_signed_post_per_company_goes_out_and_a_rerun_is_the_same_snapshot(): void
    {
        $this->enable();
        Http::fake([self::URL => Http::response(['success' => true], 201)]);

        $this->artisan('finance:publish-metrics')->expectsOutputToContain('Queued 1 company snapshot')->assertSuccessful();
        $this->artisan('finance:publish-metrics')->assertSuccessful();

        Http::assertSentCount(2);
        $bodies = [];
        Http::assertSent(function ($request) use (&$bodies) {
            $ts = $request->header('X-InfoDot-Timestamp')[0];
            $expected = 'sha256='.hash_hmac('sha256', $ts.'.'.$request->body(), 'analytics-secret');
            $bodies[] = json_decode($request->body(), true)['taken_at'];

            return $request->url() === self::URL && $request->header('X-InfoDot-Signature')[0] === $expected && abs(now()->timestamp - (int) $ts) < 400;
        });
        $this->assertSame(['2026-11-18T00:00:00Z', '2026-11-18T00:00:00Z'], $bodies);
    }

    public function test_the_job_retries_on_outages_and_gives_up_on_refusals(): void
    {
        $this->enable();
        $client = app(AnalyticsClient::class);
        $payload = app(SnapshotPublisher::class)->payload($this->b['context']);

        Http::fake([self::URL => Http::sequence()->push([], 401)->push([], 404)->push([], 422)->push([], 503)->push([], 429)->push(['ok' => true], 201)]);

        $this->assertSame(AnalyticsClient::GIVE_UP, $client->deliver($payload));   // wrong secret, or a platform missing from Analytics' catalogue
        $this->assertSame(AnalyticsClient::GIVE_UP, $client->deliver($payload));   // unknown company
        $this->assertSame(AnalyticsClient::GIVE_UP, $client->deliver($payload));   // refused payload
        $this->assertSame(AnalyticsClient::RETRY, $client->deliver($payload));
        $this->assertSame(AnalyticsClient::RETRY, $client->deliver($payload));
        $this->assertSame(AnalyticsClient::DONE, $client->deliver($payload));
    }

    public function test_the_circuit_opens_after_repeated_failures_and_leaves_analytics_alone(): void
    {
        $this->enable();
        $client = app(AnalyticsClient::class);
        $payload = app(SnapshotPublisher::class)->payload($this->b['context']);
        Http::fake([self::URL => Http::response([], 503)]);

        foreach (range(1, 3) as $i) {
            $this->assertSame(AnalyticsClient::RETRY, $client->deliver($payload));
        }

        $this->assertTrue($client->circuitOpen());
        Http::assertSentCount(3);
        $this->assertSame(AnalyticsClient::RETRY, $client->deliver($payload));
        Http::assertSentCount(3);   // not called while open
    }

    public function test_the_job_never_throws(): void
    {
        $this->enable();
        Http::fake(fn () => throw new \RuntimeException('boom'));

        (new SendAnalyticsSnapshot(['platform' => 'finance']))->handle(app(AnalyticsClient::class));

        $this->addToAssertionCount(1);
    }
}
