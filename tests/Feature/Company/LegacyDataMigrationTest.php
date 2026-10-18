<?php

namespace Tests\Feature\Company;

use App\Models\Account;
use App\Models\Insight;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Existing (legacy) rows have a user and no company. The migration must leave
 * them exactly as they were, visible to the same person in the personal ledger,
 * must be safe to run twice, and must refuse to roll back over company data.
 *
 * Not RefreshDatabase: the migration rebuilds tables, which cannot run inside the
 * wrapping transaction RefreshDatabase uses. Each test gets its own fresh in-memory database.
 */
class LegacyDataMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        // A persistent database (PostgreSQL) outlives the test, and these tests commit rows: leave it clean and fully migrated.
        $this->artisan('migrate:fresh', ['--force' => true]);

        parent::tearDown();
    }

    private const TABLES = ['accounts', 'categories', 'budgets', 'transactions', 'recurring_rules', 'finance_audit_log', 'ai_insights'];

    private function skipUnlessSqlite(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('The table-rebuild hazard is specific to SQLite.');
        }
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_10_18_300000_add_company_context_to_ledger_tables.php');
    }

    /** Put the schema back the way it was before company ledgers, and seed legacy rows into it. */
    private function legacyLedger(): array
    {
        $this->migration()->down();

        $user = User::factory()->withPersonalTeam()->create();
        $stranger = User::factory()->create();
        $now = now();

        $accountId = DB::table('accounts')->insertGetId(['user_id' => $user->id, 'name' => 'Legacy cheque', 'type' => 'checking', 'currency' => 'ZAR', 'balance' => 0, 'opening_balance_cents' => 123_456, 'opening_balance_date' => '2026-01-01', 'institution' => 'Old Bank', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('accounts')->insert(['user_id' => $stranger->id, 'name' => 'Someone else', 'type' => 'checking', 'currency' => 'ZAR', 'balance' => 0, 'opening_balance_cents' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $categoryId = DB::table('categories')->insertGetId(['user_id' => $user->id, 'name' => 'Legacy groceries', 'color' => '#112233', 'type' => 'expense', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('budgets')->insert(['user_id' => $user->id, 'category_id' => $categoryId, 'amount' => '10.00', 'amount_cents' => 1000, 'currency' => 'ZAR', 'month' => 1, 'year' => 2026, 'created_at' => $now, 'updated_at' => $now]);
        $ruleId = DB::table('recurring_rules')->insertGetId(['user_id' => $user->id, 'account_id' => $accountId, 'description' => 'Legacy rent', 'amount_cents' => 500, 'currency' => 'ZAR', 'type' => 'expense', 'recurrence_type' => 'monthly', 'recurrence_interval' => 1, 'start_date' => '2026-01-01', 'next_occurrence_date' => '2026-02-01', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('transactions')->insert(['account_id' => $accountId, 'category_id' => $categoryId, 'user_id' => $user->id, 'description' => 'Legacy groceries run', 'amount' => '45.50', 'amount_cents' => 4550, 'currency' => 'ZAR', 'type' => 'expense', 'date' => '2026-01-05', 'recurring_rule_id' => $ruleId, 'import_fingerprint' => str_repeat('a', 64), 'created_at' => $now, 'updated_at' => $now]);
        DB::table('finance_audit_log')->insert(['user_id' => $user->id, 'action' => 'created', 'actor' => 'user', 'subject_type' => 'account', 'subject_id' => $accountId, 'subject_label' => 'Legacy cheque', 'created_at' => $now]);
        DB::table('ai_insights')->insert(['user_id' => $user->id, 'insight' => 'Legacy summary', 'type' => 'reserve_runway_healthy', 'iso_week' => '2026-W05', 'created_at' => $now, 'updated_at' => $now]);

        return [$user, $stranger];
    }

    private function snapshot(): array
    {
        $out = [];
        foreach (self::TABLES as $table) {
            $out[$table] = DB::table($table)->orderBy('id')->get()->map(function ($row) {
                $row = (array) $row;
                unset($row['team_id'], $row['actor_user_id']);

                return $row;
            })->all();
        }

        return $out;
    }

    public function test_legacy_rows_survive_unchanged_and_stay_personal(): void
    {
        [$user] = $this->legacyLedger();
        $before = $this->snapshot();
        $this->assertFalse(Schema::hasColumn('accounts', 'team_id'), 'precondition: this is the legacy shape');

        $this->migration()->up();

        $this->assertSame($before, $this->snapshot(), 'no legacy value may change');
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'team_id'), "{$table} gets a team_id");
            $this->assertSame(0, DB::table($table)->whereNotNull('team_id')->count(), "{$table}: every legacy row stays personal");
            $this->assertSame(0, DB::table($table)->whereNull('user_id')->count(), "{$table}: every legacy row keeps its user");
        }
    }

    public function test_legacy_rows_are_visible_to_their_owner_in_the_personal_ledger_and_to_no_one_else(): void
    {
        [$user, $stranger] = $this->legacyLedger();
        $this->migration()->up();

        $this->actingAs($user);
        $this->assertSame(['Legacy cheque'], Account::pluck('name')->all());
        $this->assertSame(1, Transaction::count());
        $this->assertSame(1, Insight::count());
        $this->assertSame(4550, Transaction::firstOrFail()->amount_cents);
        $this->assertSame(123_456, Account::withBalance()->firstOrFail()->opening_balance_cents);

        $this->actingAs($stranger);
        $this->assertSame(['Someone else'], Account::pluck('name')->all());
        $this->assertSame(0, Transaction::count());
    }

    public function test_the_pages_still_work_for_a_user_with_only_legacy_rows(): void
    {
        [$user] = $this->legacyLedger();
        $this->migration()->up();

        $this->actingAs($user)->get(route('accounts.index'))->assertOk()->assertSee('Legacy cheque');
        $this->get(route('transactions.index'))->assertOk()->assertSee('Legacy groceries run');
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_running_the_migration_twice_changes_nothing(): void
    {
        $this->legacyLedger();
        $this->migration()->up();
        $once = $this->snapshot();
        $columns = array_map(fn ($t) => Schema::getColumnListing($t), self::TABLES);

        $this->migration()->up();

        $this->assertSame($once, $this->snapshot());
        $this->assertSame($columns, array_map(fn ($t) => Schema::getColumnListing($t), self::TABLES));
    }

    public function test_the_owner_rule_is_enforced_after_the_migration_on_legacy_tables(): void
    {
        [$user] = $this->legacyLedger();
        $this->migration()->up();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false, 'infodot_team_uuid' => (string) Str::uuid()]);

        $this->expectException(QueryException::class);

        DB::table('accounts')->where('name', 'Legacy cheque')->update(['team_id' => $team->id]);
    }

    public function test_the_unique_rules_that_protected_a_user_now_also_protect_a_company(): void
    {
        $this->legacyLedger();
        $this->migration()->up();
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false, 'infodot_team_uuid' => (string) Str::uuid()]);
        $now = now();
        $accountId = DB::table('accounts')->insertGetId(['team_id' => $team->id, 'name' => 'Co', 'type' => 'checking', 'currency' => 'ZAR', 'balance' => 0, 'opening_balance_cents' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $tx = ['account_id' => $accountId, 'team_id' => $team->id, 'description' => 'x', 'amount' => '1.00', 'amount_cents' => 100, 'type' => 'expense', 'date' => '2026-01-01', 'import_fingerprint' => str_repeat('b', 64), 'created_at' => $now, 'updated_at' => $now];
        DB::table('transactions')->insert($tx);

        try {
            DB::table('transactions')->insert($tx);
            $this->fail('a company import fingerprint must be unique within the company');
        } catch (UniqueConstraintViolationException) {
            $this->addToAssertionCount(1);
        }

        $week = ['team_id' => $team->id, 'insight' => 'x', 'type' => 'x', 'iso_week' => '2026-W10', 'created_at' => $now, 'updated_at' => $now];
        DB::table('ai_insights')->insert($week);
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('ai_insights')->insert($week);
    }

    public function test_the_migration_rolls_back_cleanly_when_there_is_no_company_data(): void
    {
        [$user] = $this->legacyLedger();
        $this->migration()->up();
        $before = $this->snapshot();

        $this->migration()->down();

        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'team_id'));
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(1, DB::table('accounts')->where('user_id', $user->id)->count());
    }

    public function test_the_migration_refuses_to_roll_back_over_company_data(): void
    {
        $this->legacyLedger();
        $this->migration()->up();
        $team = Team::forceCreate(['user_id' => User::factory()->create()->id, 'name' => 'Acme', 'personal_team' => false, 'infodot_team_uuid' => (string) Str::uuid()]);
        $now = now();
        DB::table('accounts')->insert(['team_id' => $team->id, 'name' => 'Co', 'type' => 'checking', 'currency' => 'ZAR', 'balance' => 0, 'opening_balance_cents' => 0, 'created_at' => $now, 'updated_at' => $now]);

        try {
            $this->migration()->down();
            $this->fail('rolling back over company rows would destroy them');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('company', $e->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('accounts', 'team_id'), 'the schema is left as it was');
        $this->assertSame(1, DB::table('accounts')->where('team_id', $team->id)->count(), 'the company row is untouched');
    }

    public function test_inside_a_transaction_on_sqlite_the_migration_refuses_to_rebuild_tables_that_hold_rows(): void
    {
        // SQLite keeps foreign keys on inside a transaction, so rebuilding `accounts` would cascade-delete its transactions.
        $this->skipUnlessSqlite();
        [$user] = $this->legacyLedger();
        $before = $this->snapshot();

        DB::beginTransaction();
        try {
            $this->migration()->up();
            $this->fail('the migration must not rebuild populated tables inside a transaction');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('outside a transaction', $e->getMessage());
        } finally {
            DB::rollBack();
        }

        $this->assertSame($before, $this->snapshot(), 'nothing was lost');
        $this->assertFalse(Schema::hasColumn('accounts', 'team_id'));
    }

    public function test_the_real_migrate_command_keeps_child_rows_when_it_rebuilds_the_parent_tables(): void
    {
        // Through the actual migrator (not a direct up()) and with transactions linking accounts, categories and rules.
        [$user] = $this->legacyLedger();
        $before = $this->snapshot();
        DB::table('migrations')->where('migration', '2026_10_18_300000_add_company_context_to_ledger_tables')->delete();

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        $this->assertSame($before, $this->snapshot(), 'no legacy row, including children of rebuilt tables, may disappear or change');
        $this->assertSame(1, DB::table('transactions')->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('transactions')->whereNotNull('recurring_rule_id')->whereNotNull('category_id')->count());
    }
}
