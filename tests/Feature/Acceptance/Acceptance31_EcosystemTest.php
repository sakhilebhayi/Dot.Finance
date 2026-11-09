<?php

namespace Tests\Feature\Acceptance;

use App\Models\Accounting\JournalEntry;
use App\Models\Integrations\EmallSaleLine;
use App\Models\Intelligence\FinanceNotification;
use App\Models\Tax\TaxPayrollRun;
use App\Services\Integrations\IntegrationStatus;
use App\Services\Integrations\NotifyClient;
use App\Services\Integrations\SignedRequest;
use App\Services\Intelligence\FinanceNotifier;
use App\Support\Ledger\LedgerAccess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Owner criterion 31: ecosystem integrations. Tests what exists and states, from the running code, which platforms are connected, which
 * are a written contract with nothing on the other side, and which are not possible:
 *
 *   CONNECTED      InfoDot sign-in and companies; InfoDot membership webhook (removals and role changes apply at once); Dot.Notify
 *                  (a signed outbound webhook; inert until the URL and secret are set).
 *   CONTRACT ONLY  Dot.Analytics (daily aggregate snapshot; Analytics does not list "finance" yet); Dot.Emall (signed inbound sales and
 *                  fees, staged for review; Emall has no seller fees or export to send them); Dot.HR (signed payroll summary; Dot.HR has no
 *                  payroll).  Both inbound contracts are exercised here with signed requests, as the sender would make them.
 *   NOT POSSIBLE   Projects, Files, Billing, Engage, Agents, Brain/Memory (nothing sound to build against, or deliberately not connected).
 */
class Acceptance31_EcosystemTest extends AcceptanceCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIntegration();
    }

    private function signed(string $uri, array $payload, string $secret, ?int $at = null): TestResponse
    {
        $body = json_encode($payload);
        $at ??= time();

        return $this->call('POST', $uri, [], [], [], $this->transformHeadersToServerVars(['X-InfoDot-Timestamp' => (string) $at, 'X-InfoDot-Signature' => SignedRequest::sign($secret, $at, $body), 'CONTENT_TYPE' => 'application/json']), $body);
    }

    public function test_the_status_page_and_the_code_agree_on_what_is_connected_contract_only_and_not_possible(): void
    {
        $all = collect(app(IntegrationStatus::class)->all())->keyBy('key');
        $this->assertSame(['infodot-sso', 'infodot-membership', 'notify'], $all->where('status', 'connected')->keys()->all());
        $this->assertSame(['analytics', 'emall', 'hr'], $all->where('status', 'contract_only')->keys()->all());
        $this->assertSame(['projects', 'files', 'billing', 'engage', 'agents', 'brain'], $all->where('status', 'not_possible')->keys()->all());
        foreach ($all as $row) {
            $this->assertNotSame('', $row['summary']);
            $this->assertNotSame('', $row['owner_step'], "{$row['key']} says what the owner has to do");
        }

        $text = html_entity_decode(preg_replace('/\s+/', ' ', strip_tags($this->asOwner()->get(route('intel.integrations.index'))->assertOk()->getContent())), ENT_QUOTES);
        foreach (['Dot.Analytics', 'Dot.Emall', 'Dot.HR payroll', 'Dot.Notify', 'InfoDot sign-in and companies', 'Dot.Projects', 'Dot.Files', 'Dot.Billing', 'Dot.Brain and Dot.Memory'] as $name) {
            $this->assertStringContainsString($name, $text);
        }
        $this->assertStringContainsString('Emall has no seller fees, payouts or export yet, so nothing calls it', $text, 'the page says plainly that nothing flows from Emall');
        $this->assertStringContainsString('Dot.HR has no payroll tables or API, so nothing sends it', $text);
        $this->assertStringContainsString('does not list "finance" in its metric catalogue yet', $text);

        // and the README the developers read says the same
        $readme = File::get(base_path('docs/integrations/README.md'));
        foreach ($all as $row) {
            $this->assertStringContainsString($row['name'], $readme, "{$row['name']} is in the README");
        }
    }

    public function test_infodot_membership_changes_apply_at_once_and_an_unsigned_call_changes_nothing(): void
    {
        config(['ecosystem.infodot.secret' => 'service-secret']);
        $accountant = $this->memberAs('accountant');
        $this->assertNotNull(LedgerAccess::for($accountant, $this->ledger));

        $this->call('POST', '/api/ecosystem/membership', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['event' => 'member.removed', 'team_uuid' => $this->company->infodot_team_uuid, 'email' => $accountant->email]))->assertStatus(401);
        $this->assertNotNull(LedgerAccess::for($accountant->fresh(), $this->ledger), 'an unsigned call is ignored');

        $this->signed('/api/ecosystem/membership', ['event' => 'member.role_changed', 'team_uuid' => $this->company->infodot_team_uuid, 'email' => $accountant->email, 'role' => 'viewer'], 'service-secret')->assertStatus(202);
        $this->asMember($accountant->fresh())->post(route('accounting.chart.store'), ['code' => '6877', 'name' => 'After demotion', 'class' => 'expense'])->assertForbidden();

        $this->signed('/api/ecosystem/membership', ['event' => 'member.removed', 'team_uuid' => $this->company->infodot_team_uuid, 'email' => $accountant->email], 'service-secret')->assertStatus(202);
        $this->assertNull(LedgerAccess::for($accountant->fresh(), $this->ledger), 'removed in InfoDot, gone here at once');
        $this->assertTrue($this->owner->fresh()->belongsToTeam($this->company), 'the local owner is never removed');
    }

    public function test_notify_gets_a_signed_flat_message_when_configured_and_the_app_works_without_it(): void
    {
        Mail::fake();
        Http::fake(['notify.test/*' => Http::response(['status' => 'received'])]);

        // not configured: in-app and e-mail only, nothing leaves
        $this->artisan('finance:notifications:run')->assertExitCode(0);
        Http::assertNothingSent();

        config(['integrations.notify.url' => 'https://notify.test/webhooks/finance', 'integrations.notify.secret' => 'n-secret']);
        app()->forgetInstance(NotifyClient::class);
        app()->forgetInstance(FinanceNotifier::class);
        $this->issueInvoice($this->acme, [['description' => 'Late job', 'quantity' => '1', 'unit_cents' => 100_000, 'tax_code' => 'ZA_VAT_STD']], '2026-09-01');   // due 1 October: overdue
        $this->asOwner()->post(route('tax.calendar.generate'));
        // the person chooses, per kind of message, to have it forwarded to Dot.Notify too (off by default)
        $kinds = collect(array_keys(FinanceNotification::KINDS))->mapWithKeys(fn ($k) => [$k => ['in_app' => 1, 'email' => 1, 'notify' => 1]])->all();
        $this->asOwner()->put(route('intel.notifications.preferences.update'), ['kinds' => $kinds])->assertSessionHasNoErrors();
        $this->artisan('finance:notifications:run')->assertExitCode(0);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return str_starts_with($request->url(), 'https://notify.test/')
                && $request->header('X-Dot-Signature')[0] === hash_hmac('sha256', $request->body(), 'n-secret')
                && str_starts_with($body['event'], 'finance.') && $body['recipient'] === $this->owner->email && is_scalar($body['title'])
                && ! collect($body)->contains(fn ($v) => is_array($v) || is_object($v));
        });
    }

    public function test_the_emall_contract_stages_signed_sales_for_review_and_posts_nothing_by_itself(): void
    {
        config(['integrations.emall.secret' => 'emall-secret']);
        $entries = JournalEntry::query()->forContext($this->ledger)->count();
        $delivery = ['delivery_id' => 'emall-9', 'company_uuid' => $this->company->infodot_team_uuid, 'currency' => 'ZAR', 'lines' => [[
            'order_item_id' => 'oi-1', 'order_ref' => 'ORD-1', 'sold_on' => '2026-11-12', 'description' => 'Basket', 'quantity' => 2, 'unit_price_cents' => 11500, 'discount_cents' => 0, 'total_cents' => 23000, 'tax_code' => 'ZA_VAT_STD', 'vat_cents' => 3000, 'fee_cents' => 2300,
        ]]];

        $this->signed('/api/integrations/emall/sales', $delivery, 'wrong-secret')->assertStatus(401);
        $this->signed('/api/integrations/emall/sales', $delivery, 'emall-secret')->assertStatus(201)->assertJson(['status' => 'stored', 'staged' => 1]);
        $this->signed('/api/integrations/emall/sales', $delivery, 'emall-secret')->assertStatus(200)->assertJson(['status' => 'replayed']);
        $this->assertSame(1, EmallSaleLine::query()->forContext($this->ledger)->count());
        $this->assertSame($entries, JournalEntry::query()->forContext($this->ledger)->count(), 'staged, not posted');

        $this->asOwner()->get(route('intel.integrations.emall.index'))->assertOk()->assertSee('Basket');
        $this->asMember($this->memberAs('viewer'))->get(route('intel.integrations.emall.index'))->assertOk();
        $line = EmallSaleLine::query()->forContext($this->ledger)->firstOrFail();
        $this->asMember($this->memberAs('bookkeeper'))->post(route('intel.integrations.emall.post', $line), [])->assertForbidden();
        $this->assertSame('staged', $line->fresh()->status);
    }

    public function test_the_hr_payroll_contract_creates_a_draft_summary_once_for_the_right_company_only(): void
    {
        config(['tax.payroll_import.secret' => 'hr-secret']);
        $payload = ['schema' => 'dot.hr.payroll-summary.v1', 'hr_run_id' => 'run-2026-11', 'company_uuid' => $this->company->infodot_team_uuid, 'period' => '2026-10', 'currency' => 'ZAR', 'issued_at' => now()->toIso8601String(),
            'totals' => ['gross_pay_cents' => 2_400_000, 'paye_cents' => 350_000, 'uif_employee_cents' => 17_712, 'uif_employer_cents' => 17_712, 'sdl_cents' => 24_000, 'eti_claimed_cents' => 0, 'employee_count' => 3]];
        $body = json_encode($payload);
        $sign = fn (string $secret) => ['HTTP_X-Dot-Signature' => 'sha256='.hash_hmac('sha256', $body, $secret), 'CONTENT_TYPE' => 'application/json'];

        $this->call('POST', '/api/tax/payroll-import', [], [], [], $sign('wrong'), $body)->assertStatus(401);
        $this->call('POST', '/api/tax/payroll-import', [], [], [], $sign('hr-secret'), $body)->assertStatus(201)->assertJsonPath('status', 'created');
        $this->call('POST', '/api/tax/payroll-import', [], [], [], $sign('hr-secret'), $body)->assertSuccessful();
        $run = TaxPayrollRun::query()->forContext($this->ledger)->where('period_month', '2026-10-01')->get();
        $this->assertCount(1, $run, 'the same summary twice is one run');
        $this->assertSame(['draft', 'hr_import'], [$run[0]->status, $run[0]->source], 'imported as a draft for a person to confirm, never posted by the sender');

        $unknown = json_encode(['company_uuid' => '11111111-1111-4111-8111-111111111111'] + $payload);
        $this->call('POST', '/api/tax/payroll-import', [], [], [], ['HTTP_X-Dot-Signature' => 'sha256='.hash_hmac('sha256', $unknown, 'hr-secret'), 'CONTENT_TYPE' => 'application/json'], $unknown)->assertStatus(404);
    }

    public function test_the_analytics_snapshot_is_aggregate_only_inert_until_configured_and_never_for_a_personal_ledger(): void
    {
        Http::fake();
        $this->artisan('finance:publish-metrics')->assertExitCode(0);
        Http::assertNothingSent();

        $out = new BufferedOutput;
        Artisan::call('finance:publish-metrics', ['--dry-run' => true], $out);
        $dry = $out->fetch().Artisan::output();
        $this->assertStringNotContainsString('Acme Retail', $dry, 'no customer names ever leave');
        $this->assertStringNotContainsString($this->owner->email, $dry);
        Http::assertNothingSent();
    }
}
