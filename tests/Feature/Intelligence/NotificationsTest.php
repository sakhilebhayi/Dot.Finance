<?php

namespace Tests\Feature\Intelligence;

use App\Contracts\Intelligence\ReceivablesSource;
use App\Contracts\Intelligence\TaxDeadlineSource;
use App\Enums\Capability;
use App\Mail\FinanceNotificationMail;
use App\Models\Controls\ApprovalRequest;
use App\Models\Insight;
use App\Models\Intelligence\FinanceNotification;
use App\Models\Intelligence\NotificationPreference;
use App\Services\Intelligence\FinanceNotifier;
use App\Services\Intelligence\InsightEngine;
use App\Services\Intelligence\NotificationRules;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsIntelFixtures;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use BuildsIntelFixtures;
    use RefreshDatabase;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixTime();
        $this->b = $this->business();
        Mail::fake();
    }

    private function notifier(): FinanceNotifier
    {
        return app(FinanceNotifier::class);
    }

    private function send(string $key = 'k1', $user = null, string $kind = 'system')
    {
        return $this->notifier()->send($this->b['context'], $user ?? $this->b['owner'], $kind, 'Heads up', 'Something to read.', 'https://finance.test/dashboard', $key);
    }

    private function notes($user = null)
    {
        return LedgerContext::run($this->b['context'], fn () => FinanceNotification::query()->for($user ?? $this->b['owner'])->get());
    }

    public function test_a_notification_is_stored_for_the_bell_and_e_mailed_once(): void
    {
        $note = $this->send();

        $this->assertNotNull($note);
        $this->assertNull($note->read_at);
        $this->assertSame('sent', $note->email_status);
        Mail::assertSent(FinanceNotificationMail::class, fn ($m) => $m->hasTo($this->b['owner']->email) && str_contains($m->envelope()->subject, 'Acme'));
        Mail::assertSent(FinanceNotificationMail::class, 1);

        // The same thing for the same person is never sent twice.
        $this->assertNull($this->send());
        Mail::assertSent(FinanceNotificationMail::class, 1);
        $this->assertCount(1, $this->notes());
    }

    public function test_preferences_decide_where_it_goes(): void
    {
        NotificationPreference::create($this->b['context']->ownerAttributes() + ['member_user_id' => $this->b['owner']->id, 'kind' => 'system', 'in_app' => false, 'email' => false, 'notify' => false]);

        $note = $this->send();

        $this->assertNotNull($note->read_at, 'switched off in the app: kept as a record but it does not badge');
        $this->assertSame('skipped', $note->email_status);
        Mail::assertNothingSent();
    }

    public function test_only_members_who_may_see_finance_receive_anything(): void
    {
        $this->assertNull($this->send('x', $this->user()));   // not a member

        $viewer = $this->memberOf($this->b['team'], 'viewer');
        $this->assertNotNull($this->send('y', $viewer));

        $names = $this->notifier()->recipientsWith($this->b['context'], Capability::ViewAudit)->pluck('id')->all();
        $this->assertContains($this->b['owner']->id, $names);
        $this->assertNotContains($viewer->id, $names);
    }

    public function test_dot_notify_gets_a_signed_event_only_when_connected_and_asked_for(): void
    {
        Http::fake(['notify.test/*' => Http::sequence()->push(['status' => 'received'])->push([], 500)]);
        NotificationPreference::create($this->b['context']->ownerAttributes() + ['member_user_id' => $this->b['owner']->id, 'kind' => 'system', 'in_app' => true, 'email' => false, 'notify' => true]);

        $this->assertSame('none', $this->send('a')->notify_status, 'not connected: nothing is sent');
        Http::assertNothingSent();

        config(['integrations.notify.url' => 'https://notify.test/webhooks/tok123', 'integrations.notify.secret' => 'sekrit']);
        $note = $this->send('b');

        $this->assertSame('sent', $note->notify_status);
        Http::assertSent(function ($request) {
            $body = $request->body();

            return $request->url() === 'https://notify.test/webhooks/tok123'
                && $request->header('X-Dot-Signature')[0] === hash_hmac('sha256', $body, 'sekrit')
                && ($request['event'] ?? null) === 'finance.system'
                && ($request['recipient'] ?? null) === $this->b['owner']->email;
        });

        $this->assertSame('failed', $this->send('c')->notify_status);
    }

    public function test_approvals_waiting_notifies_an_eligible_approver_once(): void
    {
        $bookkeeper = $this->memberOf($this->b['team'], 'editor');
        $manager = $this->memberOf($this->b['team'], 'admin');
        ApprovalRequest::create($this->b['context']->ownerAttributes() + [
            'subject_kind' => 'bill', 'approvable_type' => 'App\\Fake', 'approvable_id' => 1, 'amount_cents' => 600000, 'currency' => 'ZAR', 'snapshot' => [],
            'requested_by' => $bookkeeper->id, 'requested_at' => now()->subHours(3), 'status' => 'pending', 'approver_capability' => 'approve-bill', 'steps_required' => 1,
        ]);

        $rules = app(NotificationRules::class);
        $first = $rules->run($this->b['context']);
        $second = $rules->run($this->b['context']);

        $this->assertGreaterThanOrEqual(1, $first['approval_waiting']);
        $this->assertSame(0, $second['approval_waiting'], 'a rerun sends nothing');
        $this->assertCount(1, $this->notes($manager)->where('kind', 'approval_waiting'));
        $this->assertCount(0, $this->notes($bookkeeper), 'the person who asked cannot approve it, so is not told to');
        $this->assertStringContainsString('ZAR 6,000.00', $this->notes($manager)->first()->title);
    }

    public function test_tax_deadlines_notify_at_the_steps_and_never_twice_for_one_step(): void
    {
        $this->app->bind(TaxDeadlineSource::class, fn () => new class implements TaxDeadlineSource
        {
            public function dueBetween(LedgerContext $context, CarbonImmutable $from, CarbonImmutable $to): array
            {
                return [['key' => 'vat201', 'label' => 'VAT201 return', 'due_on' => '2026-11-25', 'href' => null]];
            }
        });
        $rules = app(NotificationRules::class);

        $this->assertSame(1, $rules->run($this->b['context'])['tax_deadline']);          // 7 days away: step 7
        $this->assertSame(0, $rules->run($this->b['context'])['tax_deadline']);
        $this->assertStringContainsString('due in 7 days', $this->notes()->first()->title);

        $this->fixTime('2026-11-24 10:00:00');                                           // 1 day away: a new step
        $this->assertSame(1, $rules->run($this->b['context'])['tax_deadline']);
        $this->assertCount(2, $this->notes());
    }

    public function test_overdue_summary_is_weekly_and_silent_without_invoice_data(): void
    {
        $this->assertSame(0, app(NotificationRules::class)->run($this->b['context'])['overdue_summary']);

        $this->app->bind(ReceivablesSource::class, fn () => new class implements ReceivablesSource
        {
            public function owedByCustomers(LedgerContext $context): ?array
            {
                return [];
            }

            public function overdueInvoices(LedgerContext $context, CarbonImmutable $asOf): ?array
            {
                return [['number' => 'INV-1', 'party' => 'Karoo', 'due_on' => '2026-10-01', 'days_overdue' => 48, 'open_cents' => 250000, 'currency' => 'ZAR', 'href' => null]];
            }
        });

        $rules = app(NotificationRules::class);
        $this->assertSame(1, $rules->run($this->b['context'])['overdue_summary']);
        $this->assertSame(0, $rules->run($this->b['context'])['overdue_summary']);
        $this->assertStringContainsString('ZAR 2,500.00', $this->notes()->first()->title);
    }

    public function test_serious_findings_notify_the_people_who_oversee_the_books_only(): void
    {
        $this->spend($this->b['bank'], $this->b['food'], 450000, '2026-11-10', 'Checkers');
        $this->spend($this->b['bank'], $this->b['food'], 450000, '2026-11-11', 'Checkers');
        app(InsightEngine::class)->scan($this->b['context']);
        $viewer = $this->memberOf($this->b['team'], 'viewer');

        $sent = app(NotificationRules::class)->run($this->b['context'])['insight'];

        $this->assertGreaterThanOrEqual(1, $sent);
        $this->assertGreaterThanOrEqual(1, $this->notes()->where('kind', 'insight')->count());
        $this->assertCount(0, $this->notes($viewer));
    }

    public function test_the_weekly_summary_gains_the_digest_and_stays_one_row_per_week(): void
    {
        $this->spend($this->b['bank'], $this->b['food'], 45000, '2026-11-10', 'Checkers');
        $this->spend($this->b['bank'], $this->b['food'], 45000, '2026-11-11', 'Checkers');
        $this->spend($this->b['bank'], $this->b['sales'], 500000, '2026-11-05', 'Sale', 'income');

        $this->artisan('finance:generate-insights')->assertSuccessful();
        $this->artisan('finance:generate-insights')->assertSuccessful();

        $rows = LedgerContext::run($this->b['context'], fn () => Insight::query()->get());
        $this->assertCount(1, $rows);
        $this->assertSame('2026-W47', $rows->first()->iso_week);
        $this->assertStringContainsString('1 thing needs a look in the books', $rows->first()->insight);
        $this->assertStringContainsString('Possible duplicate', $rows->first()->insight);
        $this->assertCount(1, $this->notes()->where('kind', 'weekly_digest'));
    }

    public function test_the_pages_show_only_my_notifications_and_the_bell_counts_unread(): void
    {
        $mine = $this->send('mine');
        $colleague = $this->memberOf($this->b['team'], 'admin');
        $theirs = $this->send('theirs', $colleague);

        $page = $this->inCompany($this->b['owner'], $this->b['team'])->get(route('intel.notifications.index'))->assertOk();
        $page->assertSee('Heads up');
        $this->assertSame(1, substr_count($page->getContent(), 'Something to read.'));

        $this->get(route('dashboard'))->assertOk()->assertSee('Notifications, 1 unread', false);

        $this->post(route('intel.notifications.read', $theirs))->assertNotFound();
        $this->assertNull($theirs->fresh()->read_at);

        $this->post(route('intel.notifications.read', $mine))->assertRedirect();
        $this->assertNotNull($mine->fresh()->read_at);
        $this->get(route('dashboard'))->assertDontSee('unread', false);
    }

    public function test_opening_a_notification_follows_only_a_same_site_path(): void
    {
        $note = $this->notifier()->send($this->b['context'], $this->b['owner'], 'system', 'Elsewhere', 'x', 'https://evil.example/phish', 'ext');

        $this->inCompany($this->b['owner'], $this->b['team']);
        $this->assertStringNotContainsString('evil.example', (string) $this->post(route('intel.notifications.read', $note), ['open' => 1])->headers->get('Location'));
    }

    public function test_preferences_are_saved_per_person_and_do_not_touch_anyone_else(): void
    {
        $other = $this->memberOf($this->b['team'], 'admin');

        $this->inCompany($this->b['owner'], $this->b['team'])->get(route('intel.notifications.preferences'))->assertOk()->assertSee('Tax deadlines')->assertDontSee('Via Dot.Notify');
        $this->put(route('intel.notifications.preferences.update'), ['kinds' => ['tax_deadline' => ['in_app' => '1'], 'system' => ['in_app' => '1', 'email' => '1']]])->assertRedirect();

        $tax = NotificationPreference::query()->forContext($this->b['context'])->where('member_user_id', $this->b['owner']->id)->where('kind', 'tax_deadline')->first();
        $this->assertTrue($tax->in_app);
        $this->assertFalse($tax->email);
        $this->assertSame(0, NotificationPreference::query()->forContext($this->b['context'])->where('member_user_id', $other->id)->count());

        $this->put(route('intel.notifications.preferences.update'), ['kinds' => ['bogus' => ['email' => '1']]])->assertRedirect();
        $this->assertSame(0, NotificationPreference::query()->forContext($this->b['context'])->where('kind', 'bogus')->count());
    }
}
