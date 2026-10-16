<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionRecurrenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RecurringTransactionTest extends TestCase
{
    use RefreshDatabase;

    protected function makeAccount(User $user, array $overrides = []): Account
    {
        return Account::create(array_merge([
            'user_id' => $user->id,
            'name' => 'Main Checking',
            'type' => 'checking',
            'currency' => 'ZAR',
            'balance' => 1000,
            'institution' => 'Test Bank',
        ], $overrides));
    }

    public function test_creating_a_transaction_with_a_recurrence_rule_sets_the_next_occurrence_date(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = $this->makeAccount($user);

        $this->actingAs($user)->post(route('transactions.store'), [
            'account_id' => $account->id,
            'description' => 'Rent',
            'amount' => '1200.00',
            'type' => 'expense',
            'date' => '2026-08-01',
            'recurrence_type' => 'monthly',
            'recurrence_interval' => 1,
        ]);

        $transaction = Transaction::where('description', 'Rent')->firstOrFail();
        $this->assertSame('monthly', $transaction->recurrence_type);
        $this->assertSame(1, $transaction->recurrence_interval);
        $this->assertSame('2026-09-01', $transaction->next_occurrence_date->toDateString());
    }

    public function test_a_transaction_without_a_recurrence_type_has_no_next_occurrence_date(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = $this->makeAccount($user);

        $this->actingAs($user)->post(route('transactions.store'), [
            'account_id' => $account->id,
            'description' => 'One-off purchase',
            'amount' => '50.00',
            'type' => 'expense',
            'date' => '2026-08-01',
        ]);

        $this->assertDatabaseHas('transactions', [
            'description' => 'One-off purchase',
            'recurrence_type' => null,
            'next_occurrence_date' => null,
        ]);
    }

    public function test_editing_a_transaction_never_touches_its_existing_recurrence_settings(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = $this->makeAccount($user);
        $anchor = Transaction::create([
            'account_id' => $account->id,
            'user_id' => $user->id,
            'description' => 'Rent',
            'amount' => 1200,
            'type' => 'expense',
            'date' => '2026-08-01',
            'recurrence_type' => 'monthly',
            'recurrence_interval' => 1,
            'next_occurrence_date' => '2026-09-01',
        ]);

        $this->actingAs($user)->put(route('transactions.update', $anchor), [
            'account_id' => $account->id,
            'description' => 'Rent (updated)',
            'amount' => '1250.00',
            'type' => 'expense',
            'date' => '2026-08-01',
        ]);

        $anchor->refresh();
        $this->assertSame('Rent (updated)', $anchor->description);
        $this->assertSame('monthly', $anchor->recurrence_type);
        $this->assertSame('2026-09-01', $anchor->next_occurrence_date->toDateString());
    }

    public function test_generate_due_occurrences_creates_a_new_transaction_and_advances_the_anchor(): void
    {
        Carbon::setTestNow('2026-09-01');

        $user = User::factory()->withPersonalTeam()->create();
        $account = $this->makeAccount($user);
        $anchor = Transaction::create([
            'account_id' => $account->id,
            'user_id' => $user->id,
            'description' => 'Rent',
            'amount' => 1200,
            'type' => 'expense',
            'date' => '2026-08-01',
            'recurrence_type' => 'monthly',
            'recurrence_interval' => 1,
            'next_occurrence_date' => '2026-09-01',
        ]);

        $created = app(TransactionRecurrenceService::class)->generateDueOccurrences();

        $this->assertSame(1, $created);
        $occurrence = Transaction::where('recurrence_parent_id', $anchor->id)->firstOrFail();
        $this->assertSame('Rent', $occurrence->description);
        $this->assertSame('2026-09-01', $occurrence->date->toDateString());
        $this->assertSame('2026-10-01', $anchor->fresh()->next_occurrence_date->toDateString());

        Carbon::setTestNow();
    }

    public function test_generate_due_occurrences_does_nothing_before_the_due_date(): void
    {
        Carbon::setTestNow('2026-08-15');

        $user = User::factory()->withPersonalTeam()->create();
        $account = $this->makeAccount($user);
        Transaction::create([
            'account_id' => $account->id,
            'user_id' => $user->id,
            'description' => 'Rent',
            'amount' => 1200,
            'type' => 'expense',
            'date' => '2026-08-01',
            'recurrence_type' => 'monthly',
            'recurrence_interval' => 1,
            'next_occurrence_date' => '2026-09-01',
        ]);

        $created = app(TransactionRecurrenceService::class)->generateDueOccurrences();

        $this->assertSame(0, $created);
        $this->assertSame(1, Transaction::count());

        Carbon::setTestNow();
    }

    public function test_generate_due_occurrences_catches_up_multiple_missed_periods_in_one_run(): void
    {
        Carbon::setTestNow('2026-11-01');

        $user = User::factory()->withPersonalTeam()->create();
        $account = $this->makeAccount($user);
        $anchor = Transaction::create([
            'account_id' => $account->id,
            'user_id' => $user->id,
            'description' => 'Rent',
            'amount' => 1200,
            'type' => 'expense',
            'date' => '2026-08-01',
            'recurrence_type' => 'monthly',
            'recurrence_interval' => 1,
            'next_occurrence_date' => '2026-09-01', // 3 periods behind: Sep, Oct, Nov
        ]);

        $created = app(TransactionRecurrenceService::class)->generateDueOccurrences();

        $this->assertSame(3, $created);
        $this->assertSame(4, Transaction::count()); // anchor + 3 occurrences
        $this->assertSame('2026-12-01', $anchor->fresh()->next_occurrence_date->toDateString());

        Carbon::setTestNow();
    }

    public function test_generated_occurrences_are_never_themselves_scheduled(): void
    {
        Carbon::setTestNow('2026-09-01');

        $user = User::factory()->withPersonalTeam()->create();
        $account = $this->makeAccount($user);
        Transaction::create([
            'account_id' => $account->id,
            'user_id' => $user->id,
            'description' => 'Rent',
            'amount' => 1200,
            'type' => 'expense',
            'date' => '2026-08-01',
            'recurrence_type' => 'monthly',
            'recurrence_interval' => 1,
            'next_occurrence_date' => '2026-09-01',
        ]);

        app(TransactionRecurrenceService::class)->generateDueOccurrences();
        $occurrence = Transaction::whereNotNull('recurrence_parent_id')->first();

        $this->assertNotNull($occurrence);
        $this->assertNull($occurrence->next_occurrence_date);

        // A second run the same day must not double-generate from the occurrence.
        $createdAgain = app(TransactionRecurrenceService::class)->generateDueOccurrences();
        $this->assertSame(0, $createdAgain);

        Carbon::setTestNow();
    }

    public function test_a_transaction_marked_recurring_is_flagged_is_recurring(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = $this->makeAccount($user);
        $transaction = Transaction::create([
            'account_id' => $account->id,
            'user_id' => $user->id,
            'description' => 'Rent',
            'amount' => 1200,
            'type' => 'expense',
            'date' => '2026-08-01',
            'recurrence_type' => 'monthly',
            'recurrence_interval' => 1,
            'next_occurrence_date' => '2026-09-01',
        ]);
        $plain = Transaction::create([
            'account_id' => $account->id,
            'user_id' => $user->id,
            'description' => 'Coffee',
            'amount' => 5,
            'type' => 'expense',
            'date' => '2026-08-01',
        ]);

        $this->assertTrue($transaction->isRecurring());
        $this->assertFalse($plain->isRecurring());
    }
}
