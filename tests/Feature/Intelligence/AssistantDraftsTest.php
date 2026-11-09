<?php

namespace Tests\Feature\Intelligence;

use App\Contracts\Intelligence\InvoiceReminderSender;
use App\Enums\ApprovalSubject;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\LedgerSetting;
use App\Models\Controls\ApprovalPolicy;
use App\Models\FinanceAuditLog;
use App\Models\Intelligence\AssistantDraft;
use App\Models\Transaction;
use App\Services\Intelligence\FinanceAssistant;
use App\Services\Intelligence\Sources\NullInvoiceReminderSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\BuildsAccounting;
use Tests\Concerns\BuildsIntelFixtures;
use Tests\TestCase;

class AssistantDraftsTest extends TestCase
{
    use BuildsAccounting;
    use BuildsIntelFixtures;
    use RefreshDatabase;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixTime();
        $this->b = $this->business();
    }

    private function draftFor(string $question, $user = null): AssistantDraft
    {
        $m = app(FinanceAssistant::class)->ask($user ?? $this->b['owner'], $this->b['context'], $question);

        return $m->drafts->firstOrFail();
    }

    private function counts(): array
    {
        return [
            'transactions' => Transaction::query()->forContext($this->b['context'])->count(),
            'journals' => JournalEntry::query()->forContext($this->b['context'])->count(),
            'audit' => FinanceAuditLog::query()->forContext($this->b['context'])->count(),
        ];
    }

    private function asOwner()
    {
        return $this->inCompany($this->b['owner'], $this->b['team']);
    }

    public function test_asking_for_a_draft_changes_nothing_in_the_books(): void
    {
        $before = $this->counts();

        $draft = $this->draftFor('Add an expense of R250 for fuel');

        $this->assertSame($before, $this->counts());
        $this->assertSame('pending', $draft->status);
        $this->assertSame('transaction', $draft->kind);
        $this->assertSame(25000, $draft->payload['amount_cents']);
        $this->assertSame($this->b['bank']->id, $draft->payload['account_id']); // the only account
        $this->assertTrue($draft->expires_at->isFuture());
    }

    public function test_confirming_through_the_form_records_exactly_one_transaction_with_an_audit_trail(): void
    {
        $this->enableAccounting($this->b['context']);
        $draft = $this->draftFor('Add an expense of R250 for fuel');
        $before = $this->counts();

        $this->asOwner()->get(route('intel.assistant.draft', $draft))->assertOk()->assertSee('Review and confirm')->assertSee('Confirm and record');
        $this->post(route('intel.assistant.draft.confirm', $draft), ['account_id' => $this->b['bank']->id, 'category_id' => $this->b['fuel']->id, 'amount' => '250.00', 'description' => 'Fuel', 'type' => 'expense', 'date' => '2026-11-17'])
            ->assertRedirect(route('intel.assistant.index'));

        $after = $this->counts();
        $this->assertSame($before['transactions'] + 1, $after['transactions']);
        $this->assertSame($before['journals'] + 1, $after['journals'], 'it was journalled by the normal path');

        $t = Transaction::query()->forContext($this->b['context'])->latest('id')->first();
        $this->assertSame(25000, $t->amount_cents);
        $this->assertSame($this->b['fuel']->id, $t->category_id);
        $this->assertSame('2026-11-17', $t->date->toDateString());

        $draft->refresh();
        $this->assertSame('confirmed', $draft->status);
        $this->assertSame($this->b['owner']->id, $draft->decided_by);
        $this->assertSame($t->id, $draft->result_id);

        $this->assertTrue(FinanceAuditLog::query()->forContext($this->b['context'])->where('subject_type', 'assistant_draft')->where('action', 'confirmed')->where('actor_user_id', $this->b['owner']->id)->exists());
        $this->assertTrue(FinanceAuditLog::query()->forContext($this->b['context'])->where('subject_type', 'transaction')->where('context', 'Confirmed from an assistant draft')->exists());
    }

    public function test_confirming_twice_or_replaying_never_records_it_twice(): void
    {
        $draft = $this->draftFor('Add an expense of R250 for fuel');
        $body = ['account_id' => $this->b['bank']->id, 'amount' => '250.00', 'description' => 'Fuel', 'type' => 'expense', 'date' => '2026-11-17'];

        $this->asOwner()->post(route('intel.assistant.draft.confirm', $draft), $body)->assertRedirect();
        $once = Transaction::query()->forContext($this->b['context'])->count();
        $this->post(route('intel.assistant.draft.confirm', $draft), $body)->assertRedirect();

        $this->assertSame($once, Transaction::query()->forContext($this->b['context'])->count());
    }

    public function test_a_get_cannot_confirm_and_a_post_needs_the_csrf_token(): void
    {
        $draft = $this->draftFor('Add an expense of R250 for fuel');

        $this->asOwner()->get(route('intel.assistant.draft.confirm', $draft))->assertStatus(405);

        $route = app('router')->getRoutes()->getByName('intel.assistant.draft.confirm');
        $this->assertContains('web', $route->gatherMiddleware());

        // Outside the "testing" environment the framework enforces the token: no token, no change.
        $this->app['env'] = 'production';
        $before = $this->counts();
        $this->post(route('intel.assistant.draft.confirm', $draft), ['account_id' => $this->b['bank']->id, 'amount' => '250.00', 'description' => 'Fuel', 'type' => 'expense', 'date' => '2026-11-17'])->assertStatus(419);
        $this->assertSame($before, $this->counts());
        $this->assertSame('pending', $draft->fresh()->status);
    }

    public function test_only_the_person_who_was_shown_the_draft_can_open_or_confirm_it(): void
    {
        $draft = $this->draftFor('Add an expense of R250 for fuel');
        $colleague = $this->memberOf($this->b['team'], 'admin');
        $before = $this->counts();

        $this->inCompany($colleague, $this->b['team'])->get(route('intel.assistant.draft', $draft))->assertNotFound();
        $this->post(route('intel.assistant.draft.confirm', $draft), ['account_id' => $this->b['bank']->id, 'amount' => '250.00', 'description' => 'x', 'type' => 'expense', 'date' => '2026-11-17'])->assertNotFound();
        $this->post(route('intel.assistant.draft.dismiss', $draft))->assertNotFound();

        $this->assertSame($before, $this->counts());
        $this->assertSame('pending', $draft->fresh()->status);
    }

    public function test_a_draft_from_another_ledger_is_not_found(): void
    {
        $draft = $this->draftFor('Add an expense of R250 for fuel');
        $other = $this->business('Other Ltd');

        $this->inCompany($other['owner'], $other['team'])->get(route('intel.assistant.draft', $draft))->assertNotFound();
        $this->post(route('intel.assistant.draft.confirm', $draft), [])->assertNotFound();
    }

    public function test_a_person_demoted_after_the_draft_cannot_confirm_it(): void
    {
        $member = $this->memberOf($this->b['team'], 'editor');
        $draft = $this->draftFor('Add an expense of R250 for fuel', $member);
        $this->b['team']->users()->updateExistingPivot($member->id, ['role' => 'viewer']);
        $before = $this->counts();

        $this->inCompany($member, $this->b['team'])->post(route('intel.assistant.draft.confirm', $draft), ['account_id' => $this->b['bank']->id, 'amount' => '250.00', 'description' => 'x', 'type' => 'expense', 'date' => '2026-11-17'])->assertForbidden();

        $this->assertSame($before, $this->counts());
    }

    public function test_an_expired_draft_cannot_be_confirmed(): void
    {
        $draft = $this->draftFor('Add an expense of R250 for fuel');
        $draft->forceFill(['expires_at' => now()->subMinute()])->save();
        $before = $this->counts();

        $this->asOwner()->post(route('intel.assistant.draft.confirm', $draft), ['account_id' => $this->b['bank']->id, 'amount' => '250.00', 'description' => 'x', 'type' => 'expense', 'date' => '2026-11-17'])->assertSessionHasErrors('draft');

        $this->assertSame($before, $this->counts());
        $this->assertSame('expired', $draft->fresh()->status);
    }

    public function test_a_closed_period_refuses_the_draft_and_it_stays_open_for_another_date(): void
    {
        $this->enableAccounting($this->b['context']);
        LedgerSetting::query()->forContext($this->b['context'])->first()->forceFill(['books_closed_through' => '2026-10-31'])->save();
        $draft = $this->draftFor('Add an expense of R250 for fuel');
        $before = $this->counts();

        $this->asOwner()->post(route('intel.assistant.draft.confirm', $draft), ['account_id' => $this->b['bank']->id, 'amount' => '250.00', 'description' => 'Fuel', 'type' => 'expense', 'date' => '2026-10-15'])->assertSessionHasErrors('date');

        $this->assertSame($before, $this->counts());
        $this->assertSame('pending', $draft->fresh()->status);
    }

    public function test_an_expense_that_needs_approval_cannot_go_in_through_a_draft(): void
    {
        ApprovalPolicy::create($this->b['context']->ownerAttributes() + [
            'subject_type' => ApprovalSubject::Expense->value, 'name' => 'Big expenses', 'threshold_cents' => 10000, 'currency' => 'ZAR',
            'approver_capability' => 'approve-expense', 'steps_required' => 1, 'is_active' => true,
        ]);
        $draft = $this->draftFor('Add an expense of R250 for fuel');
        $before = $this->counts();

        $this->asOwner()->post(route('intel.assistant.draft.confirm', $draft), ['account_id' => $this->b['bank']->id, 'amount' => '250.00', 'description' => 'Fuel', 'type' => 'expense', 'date' => '2026-11-17'])->assertSessionHasErrors('draft');

        $this->assertSame($before, $this->counts());
    }

    public function test_input_is_validated_like_the_normal_form(): void
    {
        $draft = $this->draftFor('Add an expense of R250 for fuel');
        $other = $this->business('Other Ltd');
        $before = $this->counts();

        $this->asOwner();
        foreach ([
            ['account_id' => $other['bank']->id],                                  // another ledger's account
            ['category_id' => $other['food']->id],                                 // another ledger's category
            ['category_id' => $this->b['sales']->id],                              // an income category on an expense
            ['amount' => '1e3'], ['amount' => '12.345'], ['amount' => '-5'],
            ['date' => 'tomorrow'],
        ] as $bad) {
            $this->post(route('intel.assistant.draft.confirm', $draft), $bad + ['account_id' => $this->b['bank']->id, 'amount' => '250.00', 'description' => 'Fuel', 'type' => 'expense', 'date' => '2026-11-17'])
                ->assertSessionHasErrors();
        }

        $this->assertSame($before, $this->counts());
    }

    public function test_dismissing_a_draft_records_nothing(): void
    {
        $draft = $this->draftFor('Add an expense of R250 for fuel');
        $before = $this->counts()['transactions'];

        $this->asOwner()->post(route('intel.assistant.draft.dismiss', $draft))->assertRedirect();

        $this->assertSame('dismissed', $draft->fresh()->status);
        $this->assertSame($before, $this->counts()['transactions']);
        $this->post(route('intel.assistant.draft.confirm', $draft), ['account_id' => $this->b['bank']->id, 'amount' => '250.00', 'description' => 'x', 'type' => 'expense', 'date' => '2026-11-17'])->assertSessionHasErrors('draft');
    }

    public function test_categorise_drafts_learn_only_from_earlier_entries_and_change_only_the_category(): void
    {
        // history: "Engen" was categorised as Fuel twice
        $this->spend($this->b['bank'], $this->b['fuel'], 50000, '2026-09-01', 'Engen');
        $this->spend($this->b['bank'], $this->b['fuel'], 52000, '2026-10-01', 'Engen');
        $known = $this->spend($this->b['bank'], null, 61000, '2026-11-09', 'Engen');
        $unknown = $this->spend($this->b['bank'], null, 9900, '2026-11-10', 'Mystery shop');

        $draft = $this->draftFor('Please categorise my uncategorised transactions');

        $this->assertSame('categorise', $draft->kind);
        $this->assertSame([$known->id], array_column($draft->payload['items'], 'transaction_id'));
        $this->assertSame($this->b['fuel']->id, $draft->payload['items'][0]['category_id']);
        $this->assertNull($known->fresh()->category_id, 'a draft changes nothing');

        $this->asOwner()->post(route('intel.assistant.draft.confirm', $draft), ['items' => [$known->id, $unknown->id]])->assertRedirect();

        $this->assertSame($this->b['fuel']->id, $known->fresh()->category_id);
        $this->assertNull($unknown->fresh()->category_id, 'an id that was not proposed is ignored');
        $this->assertSame(61000, $known->fresh()->amount_cents);
        $this->assertSame('Engen', $known->fresh()->description);
    }

    public function test_a_reminder_cannot_be_drafted_without_the_sales_module(): void
    {
        // the Sales sender is bound in the application (IntegrationServiceProvider); this is the case where none is
        $this->app->bind(InvoiceReminderSender::class, NullInvoiceReminderSender::class);
        $m = app(FinanceAssistant::class)->ask($this->b['owner'], $this->b['context'], 'Remind Acme about their overdue invoice');

        $this->assertSame('no_data', $m->status);
        $this->assertStringContainsString('Sales module', $m->answer_text);
        $this->assertCount(0, $m->drafts);
    }

    public function test_only_the_draft_executor_can_write_to_the_books_from_the_intelligence_code(): void
    {
        $forbidden = ['JournalPoster', 'GlSync', 'Transaction::create', '->approve(', 'ApprovalService::class', 'TaxRule', 'TaxRate', 'DB::table', 'PostingRequest', 'forceDelete'];
        $offenders = [];

        foreach (File::allFiles(app_path('Services/Intelligence')) as $file) {
            if ($file->getFilename() === 'DraftExecutor.php') {
                continue;
            }
            foreach ($forbidden as $token) {
                // The attention handler and the notification rules READ approvals (waitingFor); they never decide one.
                if ($token === 'ApprovalService::class' && in_array($file->getFilename(), ['AttentionHandler.php', 'NotificationRules.php'], true)) {
                    continue;
                }
                if (str_contains($file->getContents(), $token)) {
                    $offenders[] = $file->getRelativePathname().' mentions '.$token;
                }
            }
        }

        $this->assertSame([], $offenders);

        // And the assistant's controllers never call the services that write the books directly.
        foreach (File::allFiles(app_path('Http/Controllers/Intelligence')) as $file) {
            foreach (['JournalPoster', 'GlSync', 'Transaction::create'] as $token) {
                $this->assertStringNotContainsString($token, $file->getContents(), $file->getFilename());
            }
        }
    }
}
