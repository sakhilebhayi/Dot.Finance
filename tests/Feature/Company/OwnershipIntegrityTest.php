<?php

namespace Tests\Feature\Company;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\FinanceAuditLog;
use App\Models\Insight;
use App\Models\RecurringRule;
use App\Models\Transaction;
use App\Services\TransferService;
use App\Support\Ledger\LedgerContext;
use App\Support\Ledger\LedgerContextViolation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\TestCase;

/**
 * Every ledger row belongs to exactly one owner context, enforced twice: by the
 * database (a CHECK, or an equivalent trigger on SQLite) and by the models, which
 * also refuse any reference that would cross from one context into another.
 */
class OwnershipIntegrityTest extends TestCase
{
    use BuildsCompanyLedgers;
    use RefreshDatabase;

    private function accountRow(array $owner): array
    {
        return array_merge([
            'name' => 'Raw', 'type' => 'checking', 'currency' => 'ZAR', 'balance' => 0,
            'opening_balance_cents' => 0, 'created_at' => now(), 'updated_at' => now(),
        ], $owner);
    }

    public function test_the_database_refuses_a_row_with_both_owners_set(): void
    {
        $user = $this->user();
        $company = $this->company($user);

        $this->expectException(QueryException::class);

        DB::table('accounts')->insert($this->accountRow(['user_id' => $user->id, 'team_id' => $company->id]));
    }

    public function test_the_database_refuses_a_row_with_no_owner(): void
    {
        $this->expectException(QueryException::class);

        DB::table('accounts')->insert($this->accountRow(['user_id' => null, 'team_id' => null]));
    }

    public function test_the_database_refuses_to_turn_a_personal_row_into_a_two_owner_row(): void
    {
        $user = $this->user();
        $company = $this->company($user);
        $account = Account::factory()->create(['user_id' => $user->id]);

        $this->expectException(QueryException::class);

        DB::table('accounts')->where('id', $account->id)->update(['team_id' => $company->id]);
    }

    public function test_every_ledger_table_carries_the_owner_rule(): void
    {
        $user = $this->user();
        $company = $this->company($user);
        $now = now();

        $rows = [
            'categories' => ['name' => 'c', 'color' => '#000000', 'type' => 'expense', 'created_at' => $now, 'updated_at' => $now],
            'finance_audit_log' => ['action' => 'created', 'actor' => 'user', 'subject_type' => 'account', 'created_at' => $now],
            'ai_insights' => ['insight' => 'x', 'type' => 'x', 'created_at' => $now, 'updated_at' => $now],
        ];

        foreach ($rows as $table => $row) {
            try {
                DB::table($table)->insert($row + ['user_id' => $user->id, 'team_id' => $company->id]);
                $this->fail("{$table} accepted a row with two owners");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }

            try {
                DB::table($table)->insert($row + ['user_id' => null, 'team_id' => null]);
                $this->fail("{$table} accepted a row with no owner");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_models_refuse_a_missing_or_double_owner_before_the_database_does(): void
    {
        $user = $this->user();
        $company = $this->company($user);

        foreach ([['user_id' => $user->id, 'team_id' => $company->id], ['user_id' => null, 'team_id' => null]] as $owner) {
            try {
                Account::create(['name' => 'x', 'type' => 'checking', 'currency' => 'ZAR', 'opening_balance_cents' => 0] + $owner);
                $this->fail('The model accepted a bad owner pair');
            } catch (LedgerContextViolation) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_company_row_may_only_belong_to_a_company_team(): void
    {
        $user = $this->user();
        $personalTeam = $user->ownedTeams()->where('personal_team', true)->firstOrFail();

        $this->expectException(LedgerContextViolation::class);

        Account::create(['team_id' => $personalTeam->id, 'name' => 'x', 'type' => 'checking', 'currency' => 'ZAR', 'opening_balance_cents' => 0]);
    }

    public function test_a_transaction_inherits_the_context_of_its_account(): void
    {
        $company = $this->company($this->user());
        $account = $this->companyAccount($company);

        $entry = Transaction::create([
            'account_id' => $account->id, 'description' => 'Hosting', 'amount_cents' => 5000, 'type' => 'expense', 'date' => now()->toDateString(),
        ]);

        $this->assertSame($company->id, $entry->team_id);
        $this->assertNull($entry->user_id);
    }

    public function test_a_transaction_cannot_be_written_into_a_different_context_than_its_account(): void
    {
        $user = $this->user();
        $company = $this->company($user);
        $personal = Account::factory()->create(['user_id' => $user->id]);

        $this->expectException(LedgerContextViolation::class);

        Transaction::create([
            'account_id' => $personal->id, 'team_id' => $company->id, 'description' => 'x', 'amount_cents' => 1, 'type' => 'expense', 'date' => now()->toDateString(),
        ]);
    }

    public function test_a_transaction_cannot_use_a_category_from_another_context(): void
    {
        $user = $this->user();
        $company = $this->company($user);
        $companyAccount = $this->companyAccount($company);
        $personalCategory = Category::factory()->create(['user_id' => $user->id]);

        $this->expectException(LedgerContextViolation::class);

        Transaction::create([
            'account_id' => $companyAccount->id, 'category_id' => $personalCategory->id, 'description' => 'x', 'amount_cents' => 1, 'type' => 'expense', 'date' => now()->toDateString(),
        ]);
    }

    public function test_a_transaction_cannot_use_a_category_from_another_company(): void
    {
        $company = $this->company($this->user());
        $other = $this->company($this->user(), 'Globex');
        $account = $this->companyAccount($company);
        $foreign = $this->companyCategory($other);

        $this->expectException(LedgerContextViolation::class);

        Transaction::create([
            'account_id' => $account->id, 'category_id' => $foreign->id, 'description' => 'x', 'amount_cents' => 1, 'type' => 'expense', 'date' => now()->toDateString(),
        ]);
    }

    public function test_an_existing_transaction_cannot_be_repointed_at_an_account_in_another_context(): void
    {
        $user = $this->user();
        $company = $this->company($user);
        $personal = Account::factory()->create(['user_id' => $user->id]);
        $entry = $this->entryOn($personal);
        $shared = $this->companyAccount($company);

        $this->expectException(LedgerContextViolation::class);

        $entry->update(['account_id' => $shared->id]);
    }

    public function test_a_budget_cannot_use_a_category_from_another_context(): void
    {
        $user = $this->user();
        $company = $this->company($user);
        $personalCategory = Category::factory()->create(['user_id' => $user->id]);

        $this->expectException(LedgerContextViolation::class);

        Budget::create(['team_id' => $company->id, 'category_id' => $personalCategory->id, 'amount_cents' => 100, 'currency' => 'ZAR', 'month' => 1, 'year' => 2026]);
    }

    public function test_a_recurring_rule_cannot_use_an_account_or_category_from_another_context(): void
    {
        $user = $this->user();
        $company = $this->company($user);
        $personal = Account::factory()->create(['user_id' => $user->id]);
        $companyAccount = $this->companyAccount($company);
        $personalCategory = Category::factory()->create(['user_id' => $user->id]);
        $base = ['description' => 'x', 'amount_cents' => 1, 'type' => 'expense', 'recurrence_type' => 'monthly', 'recurrence_interval' => 1, 'start_date' => '2026-01-01', 'next_occurrence_date' => '2026-02-01'];

        foreach ([
            ['team_id' => $company->id, 'account_id' => $personal->id],
            ['user_id' => $user->id, 'account_id' => $companyAccount->id],
            ['team_id' => $company->id, 'account_id' => $companyAccount->id, 'category_id' => $personalCategory->id],
        ] as $attributes) {
            try {
                RecurringRule::create($base + $attributes);
                $this->fail('A recurring rule crossed contexts');
            } catch (LedgerContextViolation) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_transfer_cannot_cross_from_a_personal_account_into_a_company_account(): void
    {
        $user = $this->user();
        $company = $this->company($user);
        $personal = Account::factory()->create(['user_id' => $user->id]);
        $shared = $this->companyAccount($company);

        $this->expectException(LedgerContextViolation::class);

        app(TransferService::class)->create($user, $personal, $shared, 1000, null, now(), 'x', null);
    }

    public function test_a_transfer_cannot_cross_between_two_companies(): void
    {
        $user = $this->user();
        $one = $this->company($user, 'One');
        $two = $this->company($user, 'Two');

        $this->expectException(LedgerContextViolation::class);

        app(TransferService::class)->create($user, $this->companyAccount($one), $this->companyAccount($two), 1000, null, now(), 'x', null);
    }

    public function test_a_transfer_inside_a_company_works_and_both_legs_belong_to_it(): void
    {
        $user = $this->user();
        $company = $this->company($user);
        $from = $this->companyAccount($company);
        $to = $this->companyAccount($company);

        $legs = app(TransferService::class)->create($user, $from, $to, 2500, null, now(), 'Move', null);

        $this->assertSame([$company->id, $company->id], [$legs['out']->team_id, $legs['in']->team_id]);
        $this->assertSame([null, null], [$legs['out']->user_id, $legs['in']->user_id]);
    }

    public function test_a_row_can_never_be_moved_to_another_owner(): void
    {
        $user = $this->user();
        $company = $this->company($user);
        $account = Account::factory()->create(['user_id' => $user->id]);

        $this->expectException(LedgerContextViolation::class);

        $account->forceFill(['user_id' => null, 'team_id' => $company->id])->save();
    }

    public function test_a_personal_row_cannot_be_handed_to_another_user(): void
    {
        $user = $this->user();
        $account = Account::factory()->create(['user_id' => $user->id]);

        $this->expectException(LedgerContextViolation::class);

        $account->forceFill(['user_id' => $this->user()->id])->save();
    }

    public function test_an_audit_row_and_an_insight_belong_to_exactly_one_context(): void
    {
        $user = $this->user();
        $company = $this->company($user);

        foreach ([FinanceAuditLog::class, Insight::class] as $model) {
            $attributes = $model === Insight::class
                ? ['insight' => 'x', 'type' => 'x']
                : ['action' => 'created', 'actor' => 'user', 'subject_type' => 'account'];

            try {
                $model::create($attributes + ['user_id' => $user->id, 'team_id' => $company->id]);
                $this->fail(class_basename($model).' accepted two owners');
            } catch (LedgerContextViolation) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_context_value_round_trips_through_a_row(): void
    {
        $company = $this->company($this->user());
        $account = $this->companyAccount($company);

        $this->assertTrue($account->ledgerContext()->equals(LedgerContext::company($company)));
    }
}
