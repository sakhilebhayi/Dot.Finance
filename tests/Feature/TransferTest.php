<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TransferTest extends TestCase
{
    use RefreshDatabase;

    private function payload(Account $from, Account $to, array $overrides = []): array
    {
        return array_merge([
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => '250.00',
            'date' => now()->toDateString(),
            'description' => 'Move to savings',
        ], $overrides);
    }

    public function test_a_transfer_creates_two_linked_legs_atomically(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $from = Account::factory()->for($user)->create();
        $to = Account::factory()->for($user)->create();

        $this->actingAs($user)->post(route('transfers.store'), $this->payload($from, $to))
            ->assertRedirect(route('transactions.index'));

        $legs = Transaction::where('type', 'transfer')->orderBy('id')->get();
        $this->assertCount(2, $legs);

        $out = $legs->firstWhere('transfer_direction', 'out');
        $in = $legs->firstWhere('transfer_direction', 'in');

        $this->assertSame($from->id, $out->account_id);
        $this->assertSame($to->id, $in->account_id);
        $this->assertSame(25_000, $out->amount_cents);
        $this->assertSame(25_000, $in->amount_cents);
        $this->assertNotNull($out->transfer_group);
        $this->assertSame($out->transfer_group, $in->transfer_group);
        $this->assertSame($out->date->toDateString(), $in->date->toDateString());
    }

    public function test_a_transfer_that_fails_halfway_leaves_no_orphan_leg(): void
    {
        $user = User::factory()->create();
        $from = Account::factory()->for($user)->create();
        $to = Account::factory()->for($user)->create();

        $armed = true;
        $listener = function (Transaction $tx) use (&$armed) {
            if ($armed && $tx->transfer_direction === 'in') {
                throw new \RuntimeException('simulated failure writing the second leg');
            }
        };
        Transaction::creating($listener);

        try {
            app(TransferService::class)->create($user, $from, $to, 1_000, null, Carbon::today(), 'Doomed', null);
            $this->fail('Expected the simulated failure');
        } catch (\RuntimeException) {
            // expected
        } finally {
            $armed = false; // the listener stays registered for the rest of the process; make it inert
        }

        $this->assertSame(0, Transaction::withoutGlobalScopes()->where('type', 'transfer')->count());
    }

    public function test_source_and_destination_must_differ(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->for($user)->create();

        $this->actingAs($user)->post(route('transfers.store'), $this->payload($account, $account))
            ->assertSessionHasErrors('to_account_id');

        $this->assertSame(0, Transaction::count());
    }

    public function test_both_accounts_must_belong_to_the_user(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $stranger = User::factory()->create();
        $mine = Account::factory()->for($user)->create();
        $theirs = Account::factory()->for($stranger)->create();

        $this->actingAs($user)->post(route('transfers.store'), $this->payload($mine, $theirs))
            ->assertSessionHasErrors('to_account_id');
        $this->actingAs($user)->post(route('transfers.store'), $this->payload($theirs, $mine))
            ->assertSessionHasErrors('from_account_id');

        $this->assertSame(0, Transaction::withoutGlobalScopes()->count());
    }

    public function test_a_cross_currency_transfer_requires_an_explicit_converted_amount(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $zar = Account::factory()->for($user)->currency('ZAR')->create();
        $usd = Account::factory()->for($user)->currency('USD')->create();

        $this->actingAs($user)->post(route('transfers.store'), $this->payload($zar, $usd, ['amount' => '1850.00']))
            ->assertSessionHasErrors('converted_amount');
        $this->assertSame(0, Transaction::count());

        $this->actingAs($user)->post(route('transfers.store'), $this->payload($zar, $usd, ['amount' => '1850.00', 'converted_amount' => '100.00']))
            ->assertSessionHasNoErrors();

        $out = Transaction::where('transfer_direction', 'out')->firstOrFail();
        $in = Transaction::withoutGlobalScopes()->where('transfer_direction', 'in')->firstOrFail();

        $this->assertSame(185_000, $out->amount_cents);
        $this->assertSame('ZAR', $out->currency);
        $this->assertSame(10_000, $in->amount_cents);
        $this->assertSame('USD', $in->currency);
    }

    public function test_a_same_currency_transfer_rejects_a_converted_amount(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $a = Account::factory()->for($user)->create();
        $b = Account::factory()->for($user)->create();

        $this->actingAs($user)->post(route('transfers.store'), $this->payload($a, $b, ['converted_amount' => '10.00']))
            ->assertSessionHasErrors('converted_amount');
    }

    public function test_transfers_cannot_be_created_through_the_ordinary_transaction_form(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->for($user)->create();

        $this->actingAs($user)->post(route('transactions.store'), [
            'account_id' => $account->id,
            'description' => 'One-legged transfer',
            'amount' => '10.00',
            'type' => 'transfer',
            'date' => now()->toDateString(),
        ])->assertSessionHasErrors('type');

        $this->assertSame(0, Transaction::count());
    }

    public function test_deleting_one_leg_deletes_both(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $from = Account::factory()->for($user)->opening(100_000, null)->create();
        $to = Account::factory()->for($user)->opening(0, null)->create();
        app(TransferService::class)->create($user, $from, $to, 40_000, null, Carbon::today(), 'Reversible', null);

        $in = Transaction::withoutGlobalScopes()->where('transfer_direction', 'in')->firstOrFail();

        $this->actingAs($user)->delete(route('transactions.destroy', $in))->assertRedirect(route('transactions.index'));

        $this->assertSame(0, Transaction::count());
        $this->assertSame(100_000, Account::withBalance()->findOrFail($from->id)->balanceCents());
        $this->assertSame(0, Account::withBalance()->findOrFail($to->id)->balanceCents());
    }

    public function test_transfers_are_not_counted_as_spending_or_income_for_the_dashboard(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $a = Account::factory()->for($user)->create();
        $b = Account::factory()->for($user)->create();
        app(TransferService::class)->create($user, $a, $b, 55_555_00, null, Carbon::today(), 'Internal', null);

        $this->actingAs($user)->get(route('dashboard'))->assertOk()
            ->assertViewHas('position', fn (array $p) => ($p['ZAR']['income_cents'] ?? 0) === 0 && ($p['ZAR']['expense_cents'] ?? 0) === 0);
    }

    public function test_the_transfer_form_lists_only_the_users_own_accounts(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $other = User::factory()->create();
        Account::factory()->for($user)->create(['name' => 'My own transfer source']);
        Account::factory()->for($user)->create(['name' => 'My own transfer target']);
        Account::factory()->for($other)->create(['name' => 'Somebody elses transfer source']);

        $this->actingAs($user)->get(route('transfers.create'))->assertOk()
            ->assertSee('My own transfer source')
            ->assertDontSee('Somebody elses transfer source');
    }

    public function test_a_transfer_leg_cannot_be_edited_through_the_transaction_edit_form(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $a = Account::factory()->for($user)->create();
        $b = Account::factory()->for($user)->create();
        app(TransferService::class)->create($user, $a, $b, 1_000, null, Carbon::today(), 'Locked pair', null);
        $leg = Transaction::withoutGlobalScopes()->where('transfer_direction', 'out')->firstOrFail();

        $this->actingAs($user)->get(route('transactions.edit', $leg))->assertRedirect(route('transactions.index'));
        $this->actingAs($user)->put(route('transactions.update', $leg), [
            'account_id' => $a->id, 'description' => 'tamper', 'amount' => '5.00', 'type' => 'expense', 'date' => now()->toDateString(),
        ])->assertRedirect(route('transactions.index'));

        $this->assertSame('transfer', $leg->fresh()->type);
        $this->assertSame(1_000, $leg->fresh()->amount_cents);
    }
}
