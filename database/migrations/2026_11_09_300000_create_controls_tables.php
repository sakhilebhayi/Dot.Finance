<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-44 Controls (F3): roles per ledger membership, approval policies, approval requests and
 * their decisions, spend limits and access reviews.
 *
 * Every table follows the ledger owner pattern: exactly one of user_id (a person's personal
 * ledger) or team_id (a company), enforced by a CHECK on PostgreSQL and equivalent triggers on
 * SQLite. Enumerations and ranges get the same treatment. approval_decisions and access_reviews
 * are evidence: an UPDATE is refused by the database (deletes only happen through a person's
 * or company's own cascade).
 *
 * Uniqueness that has to hold across the owner pair uses a COALESCE expression index, because a
 * plain unique index treats NULL owners as all different.
 */
return new class extends Migration
{
    private const OWNED = ['ledger_member_roles', 'approval_policies', 'approval_requests', 'approval_decisions', 'spend_limits', 'access_reviews'];

    public function up(): void
    {
        Schema::create('ledger_member_roles', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            // The person whose role this is (user_id above is the LEDGER owner, never the member).
            $table->foreignId('member_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 20);
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at')->useCurrent();
            $table->timestamps();

            $table->index(['team_id', 'member_user_id']);
        });

        Schema::create('approval_policies', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->string('subject_type', 20);
            $table->string('name', 160);
            // Approval is needed when the amount is GREATER than this; NULL means every amount.
            $table->unsignedBigInteger('threshold_cents')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('approver_capability', 40);
            $table->string('min_role', 20)->nullable();
            $table->unsignedSmallInteger('steps_required')->default(1);
            $table->boolean('allow_self_approval')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('preset_key', 40)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['team_id', 'subject_type', 'is_active']);
            $table->index(['user_id', 'subject_type', 'is_active']);
        });

        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->foreignId('approval_policy_id')->nullable()->constrained('approval_policies')->nullOnDelete();
            $table->string('subject_kind', 20);
            $table->string('approvable_type', 160);
            $table->unsignedBigInteger('approvable_id');
            $table->string('label')->nullable();
            $table->bigInteger('amount_cents')->default(0);
            $table->char('currency', 3)->nullable();
            // The subject as it stood when approval was asked for: {kind, amount_cents, currency, label}.
            $table->json('snapshot');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->useCurrent();
            $table->text('request_note')->nullable();
            $table->string('status', 10)->default('pending');
            // Copied from the policy at request time so editing a policy never changes a request in flight.
            $table->string('approver_capability', 40);
            $table->string('min_role', 20)->nullable();
            $table->unsignedSmallInteger('steps_required')->default(1);
            $table->unsignedSmallInteger('steps_done')->default(0);
            $table->boolean('allow_self_approval')->default(false);
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index(['approvable_type', 'approvable_id']);
        });

        Schema::create('approval_decisions', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->foreignId('approval_request_id')->constrained('approval_requests')->cascadeOnDelete();
            $table->unsignedSmallInteger('step');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 10);
            $table->bigInteger('amount_cents')->default(0);
            $table->char('currency', 3)->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // One decision per step: the database settles a race between two approvers.
            $table->unique(['approval_request_id', 'step']);
            $table->index(['decided_by', 'created_at']);
        });

        Schema::create('spend_limits', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            // Exactly one of: a person, or a role.
            $table->foreignId('member_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('role', 20)->nullable();
            $table->char('currency', 3);
            $table->string('period', 12);
            $table->unsignedBigInteger('limit_cents');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['team_id', 'member_user_id']);
            $table->index(['user_id', 'member_user_id']);
        });

        Schema::create('access_reviews', function (Blueprint $table) {
            $table->id();
            $this->owner($table);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->useCurrent();
            $table->text('notes')->nullable();
            // Who had which role when the review was confirmed.
            $table->json('snapshot');
            $table->unsignedInteger('member_count')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['team_id', 'reviewed_at']);
            $table->index(['user_id', 'reviewed_at']);
        });

        foreach (self::OWNED as $name) {
            $this->exactlyOneOwner($name);
        }

        $roles = "'".implode("','", ['owner', 'finance_manager', 'accountant', 'bookkeeper', 'approver', 'viewer'])."'";
        $subjects = "'".implode("','", ['expense', 'bill', 'payment', 'journal', 'invoice', 'period_close', 'budget'])."'";

        // Grants are never "owner": the owner is the company's local owner.
        $this->check('ledger_member_roles', 'role_valid', "{r}role IN ({$roles}) AND {r}role <> 'owner'");
        $this->check('approval_policies', 'subject_valid', "{r}subject_type IN ({$subjects})");
        $this->check('approval_policies', 'steps_valid', '{r}steps_required BETWEEN 1 AND 3');
        $this->check('approval_policies', 'currency_with_threshold', '{r}threshold_cents IS NULL OR {r}currency IS NOT NULL');
        $this->check('approval_policies', 'min_role_valid', "{r}min_role IS NULL OR {r}min_role IN ({$roles})");
        $this->check('approval_requests', 'status_valid', "{r}status IN ('pending','approved','rejected','cancelled')");
        $this->check('approval_requests', 'subject_valid', "{r}subject_kind IN ({$subjects})");
        $this->check('approval_requests', 'steps_valid', '{r}steps_required BETWEEN 1 AND 3 AND {r}steps_done BETWEEN 0 AND {r}steps_required');
        $this->check('approval_decisions', 'decision_valid', "{r}decision IN ('approved','rejected')");
        $this->check('approval_decisions', 'step_valid', '{r}step BETWEEN 1 AND 3');
        $this->check('spend_limits', 'period_valid', "{r}period IN ('per_approval','daily','weekly','monthly')");
        $this->check('spend_limits', 'who_valid', "({r}member_user_id IS NOT NULL AND {r}role IS NULL) OR ({r}member_user_id IS NULL AND {r}role IN ({$roles}))");

        // One role grant per person per ledger; one limit per person/role, currency and period; one pending request per subject.
        $this->uniqueIndex('ledger_member_roles', 'ledger_member_roles_one_per_member', 'COALESCE(user_id, 0), COALESCE(team_id, 0), member_user_id');
        $this->uniqueIndex('spend_limits', 'spend_limits_one_per_scope', "COALESCE(user_id, 0), COALESCE(team_id, 0), COALESCE(member_user_id, 0), COALESCE(role, ''), currency, period");
        $this->uniqueIndex('approval_requests', 'approval_requests_one_pending_per_subject', 'approvable_type, approvable_id', "status = 'pending'");

        $this->refuseUpdates('approval_decisions');
        $this->refuseUpdates('access_reviews');
    }

    public function down(): void
    {
        foreach (array_reverse(self::OWNED) as $name) {
            Schema::dropIfExists($name);
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS controls_refuse_update()');
        }
    }

    private function owner(Blueprint $table): void
    {
        $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
        // restrict, never cascade: deleting a team must not silently delete a company's records.
        $table->foreignId('team_id')->nullable()->constrained('teams')->restrictOnDelete();
    }

    private function exactlyOneOwner(string $table): void
    {
        $this->check($table, 'owner_rule', '({r}user_id IS NOT NULL AND {r}team_id IS NULL) OR ({r}user_id IS NULL AND {r}team_id IS NOT NULL)', 'a ledger row belongs to exactly one owner (user_id or team_id)');
    }

    /**
     * A table-level rule. {r} is the row prefix: empty in a PostgreSQL CHECK, NEW. in the SQLite
     * trigger that does the same job (SQLite cannot add a CHECK to a table after creation).
     */
    private function check(string $table, string $name, string $expression, ?string $message = null): void
    {
        $message ??= "{$table}: {$name}";

        if (DB::getDriverName() === 'sqlite') {
            $when = 'NOT ('.str_replace('{r}', 'NEW.', $expression).')';
            DB::unprepared("CREATE TRIGGER {$table}_{$name}_insert BEFORE INSERT ON {$table} WHEN {$when} BEGIN SELECT RAISE(ABORT, '{$message}'); END");
            DB::unprepared("CREATE TRIGGER {$table}_{$name}_update BEFORE UPDATE ON {$table} WHEN {$when} BEGIN SELECT RAISE(ABORT, '{$message}'); END");

            return;
        }

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$name} CHECK (".str_replace('{r}', '', $expression).')');
    }

    private function uniqueIndex(string $table, string $name, string $columns, ?string $where = null): void
    {
        DB::statement("CREATE UNIQUE INDEX {$name} ON {$table} ({$columns})".($where ? " WHERE {$where}" : ''));
    }

    private function refuseUpdates(string $table): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER {$table}_refuse_update BEFORE UPDATE ON {$table} BEGIN SELECT RAISE(ABORT, '{$table} rows are evidence and cannot be updated'); END");

            return;
        }

        DB::unprepared("CREATE OR REPLACE FUNCTION controls_refuse_update() RETURNS trigger AS \$\$ BEGIN RAISE EXCEPTION '% rows are evidence and cannot be updated', TG_TABLE_NAME; END; \$\$ LANGUAGE plpgsql");
        DB::unprepared("CREATE TRIGGER {$table}_refuse_update BEFORE UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION controls_refuse_update()");
    }
};
