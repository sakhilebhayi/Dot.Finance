<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\FinanceAuditLog;
use App\Models\RecurringRule;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use LogicException;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private function logFor(string $type, int $id, ?string $action = null): Collection
    {
        return FinanceAuditLog::withoutGlobalScopes()
            ->where('subject_type', $type)->where('subject_id', $id)
            ->when($action, fn ($q) => $q->where('action', $action))
            ->orderBy('id')->get();
    }

    public function test_creating_a_transaction_writes_an_audit_row_without_the_free_text(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->for($user)->create();

        $this->actingAs($user)->post(route('transactions.store'), [
            'account_id' => $account->id, 'description' => 'Distinctive-private-memo-xyzzy', 'notes' => 'Secret-note-plugh',
            'amount' => '123.45', 'type' => 'expense', 'date' => '2026-08-01',
        ])->assertRedirect();

        $tx = Transaction::firstOrFail();
        $row = $this->logFor('transaction', $tx->id, 'created')->sole();

        $this->assertSame($user->id, $row->user_id);
        $this->assertSame('user', $row->actor);
        $this->assertSame(12_345, $row->changes['amount_cents']['after']);
        $this->assertSame('expense', $row->changes['type']['after']);
        $this->assertSame('2026-08-01', $row->changes['date']['after']);
        // free text: the field name is recorded, the words are not
        $this->assertArrayHasKey('description', $row->changes);
        $this->assertTrue($row->changes['description']['changed']);
        $json = json_encode($row->getRawOriginal());
        $this->assertStringNotContainsString('Distinctive-private-memo-xyzzy', $json);
        $this->assertStringNotContainsString('Secret-note-plugh', $json);
    }

    public function test_updating_a_transaction_records_before_and_after_for_the_fields_that_changed_only(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->for($user)->create();
        $tx = Transaction::factory()->for($account)->cents(10_000)->on('2026-08-01')->create(['description' => 'Before text']);

        $this->actingAs($user)->put(route('transactions.update', $tx), [
            'account_id' => $account->id, 'description' => 'After text', 'amount' => '150.00', 'type' => 'expense', 'date' => '2026-08-01',
        ])->assertRedirect();

        $row = $this->logFor('transaction', $tx->id, 'updated')->sole();
        $this->assertSame(['before' => 10_000, 'after' => 15_000], $row->changes['amount_cents']);
        $this->assertArrayNotHasKey('date', $row->changes);
        $this->assertArrayNotHasKey('type', $row->changes);
        $this->assertTrue($row->changes['description']['changed']);
        $this->assertStringNotContainsString('Before text', json_encode($row->getRawOriginal()));
    }

    public function test_deleting_a_transaction_is_logged_and_the_row_survives_as_a_soft_delete(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $tx = Transaction::factory()->for(Account::factory()->for($user)->create())->cents(5_000)->create();

        $this->actingAs($user)->delete(route('transactions.destroy', $tx))->assertRedirect();

        $this->assertSoftDeleted('transactions', ['id' => $tx->id]);
        $row = $this->logFor('transaction', $tx->id, 'deleted')->sole();
        $this->assertSame(5_000, $row->changes['amount_cents']['before']);
    }

    public function test_accounts_categories_and_budgets_are_audited_too(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->for($user)->create();
        $category = Category::factory()->for($user)->create();
        $budget = Budget::factory()->for($user)->create(['category_id' => $category->id]);

        $this->assertCount(1, $this->logFor('account', $account->id, 'created'));
        $this->assertCount(1, $this->logFor('category', $category->id, 'created'));
        $this->assertCount(1, $this->logFor('budget', $budget->id, 'created'));

        $this->actingAs($user);
        $budget->update(['amount_cents' => 222_00]);
        $this->assertSame(['before' => 100_000, 'after' => 22_200], $this->logFor('budget', $budget->id, 'updated')->sole()->changes['amount_cents']);

        $category->delete();
        $this->assertCount(1, $this->logFor('category', $category->id, 'deleted'));
    }

    public function test_the_audit_model_refuses_to_be_updated_or_deleted(): void
    {
        $user = User::factory()->create();
        Account::factory()->for($user)->create();
        $row = FinanceAuditLog::withoutGlobalScopes()->firstOrFail();

        try {
            $row->update(['action' => 'tampered']);
            $this->fail('update should be refused');
        } catch (LogicException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            $row->delete();
            $this->fail('delete should be refused');
        } catch (LogicException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->assertSame('created', $row->fresh()->action);
    }

    public function test_the_audit_log_cannot_be_rewritten_through_a_query_either(): void
    {
        Account::factory()->create();
        $before = FinanceAuditLog::withoutGlobalScopes()->count();

        foreach ([
            fn () => FinanceAuditLog::withoutGlobalScopes()->update(['action' => 'x']),
            fn () => FinanceAuditLog::withoutGlobalScopes()->delete(),
            fn () => FinanceAuditLog::withoutGlobalScopes()->where('id', '>', 0)->update(['action' => 'x']),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('a bulk change to the audit log should be refused');
            } catch (LogicException) {
                // expected
            }
        }

        $this->assertSame($before, FinanceAuditLog::withoutGlobalScopes()->count());
        $this->assertSame(0, FinanceAuditLog::withoutGlobalScopes()->where('action', 'x')->count());
    }

    public function test_the_audit_page_lists_only_the_users_own_entries_and_offers_no_way_to_change_them(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $other = User::factory()->create();
        Account::factory()->for($user)->create(['name' => 'Audit page mine']);
        Account::factory()->for($other)->create(['name' => 'Audit page theirs']);

        $this->actingAs($user)->get(route('audit.index'))->assertOk()
            ->assertSee('Audit page mine')
            ->assertDontSee('Audit page theirs');

        // There is no write route at all: the collection answers 405, a single entry has no route (404).
        $row = FinanceAuditLog::firstOrFail();
        $this->actingAs($user)->post('/audit')->assertStatus(405);
        $this->actingAs($user)->put('/audit')->assertStatus(405);
        $this->actingAs($user)->delete('/audit')->assertStatus(405);
        $this->assertContains($this->actingAs($user)->put('/audit/'.$row->id)->status(), [404, 405]);
        $this->assertContains($this->actingAs($user)->delete('/audit/'.$row->id)->status(), [404, 405]);
        $this->assertSame(1, FinanceAuditLog::where('id', $row->id)->count());
    }

    public function test_guests_cannot_see_the_audit_page(): void
    {
        $this->get(route('audit.index'))->assertRedirect(route('login'));
    }

    public function test_the_audit_page_filters_by_subject_type_and_shows_money_in_the_right_currency(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $usd = Account::factory()->for($user)->currency('USD')->create(['name' => 'Audit filter dollar account']);
        Account::factory()->for($user)->create(['name' => 'Audit filter bystander account']);
        Transaction::factory()->for($usd)->cents(98_765_43)->create();

        $this->actingAs($user)->get(route('audit.index', ['subject' => 'transaction']))->assertOk()
            ->assertSee('USD 98,765.43')
            ->assertSee('Audit filter dollar account') // the transaction row names its account
            ->assertDontSee('Audit filter bystander account');

        $this->actingAs($user)->get(route('audit.index', ['subject' => 'account']))->assertOk()
            ->assertSee('Audit filter bystander account')
            ->assertDontSee('USD 98,765.43');
    }

    public function test_transfer_legs_are_audited_with_a_shared_context(): void
    {
        $user = User::factory()->create();
        $a = Account::factory()->for($user)->create();
        $b = Account::factory()->for($user)->create();
        $legs = app(TransferService::class)->create($user, $a, $b, 1_000, null, Carbon::today(), 'Linked', null);

        $out = $this->logFor('transaction', $legs['out']->id, 'created')->sole();
        $in = $this->logFor('transaction', $legs['in']->id, 'created')->sole();

        $this->assertSame('transfer:'.$legs['out']->transfer_group, $out->context);
        $this->assertSame($out->context, $in->context);
    }

    public function test_rows_written_by_the_scheduler_are_marked_as_system_actions(): void
    {
        Carbon::setTestNow('2026-09-01');
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        RecurringRule::factory()->for($user)->create([
            'account_id' => $account->id, 'amount_cents' => 5_000, 'start_date' => '2026-08-01', 'next_occurrence_date' => '2026-09-01',
        ]);

        $this->artisan('finance:generate-recurring-transactions')->assertSuccessful();

        $tx = Transaction::withoutGlobalScopes()->whereNotNull('recurring_rule_id')->firstOrFail();
        $row = $this->logFor('transaction', $tx->id, 'created')->sole();
        $this->assertSame('system', $row->actor);
        $this->assertStringStartsWith('recurring:', $row->context);

        Carbon::setTestNow();
    }
}
