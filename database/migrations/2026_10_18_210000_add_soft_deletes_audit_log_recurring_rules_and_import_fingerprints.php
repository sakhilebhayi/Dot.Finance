<?php

use App\Services\LegacyRecurrenceBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft deletes, an append-only audit log, recurring rules that can be edited /
 * paused / stopped, and per-row import fingerprints.
 *
 * Nothing is dropped. The legacy recurrence_* columns on transactions stay in
 * place (and unread) so a rollback of the code still has its data; each legacy
 * "anchor" transaction is converted into a recurring_rules row by
 * LegacyRecurrenceBackfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['accounts', 'categories', 'budgets', 'transactions'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        // With soft deletes a deleted budget would block recreating the same
        // one, so the database no longer enforces uniqueness here; the
        // controller does, among live rows only.
        Schema::table('budgets', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'category_id', 'currency', 'month', 'year']);
            $table->index(['user_id', 'category_id', 'currency', 'month', 'year'], 'budgets_period_lookup_index');
        });

        Schema::create('recurring_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->bigInteger('amount_cents');
            $table->string('currency', 3);
            $table->string('type', 10); // income | expense
            $table->string('notes')->nullable();
            $table->string('recurrence_type', 10); // daily | weekly | monthly | yearly
            $table->unsignedInteger('recurrence_interval')->default(1);
            // Occurrences are start_date + n * interval, so a rule that began on
            // the 31st lands on the last day of short months and returns to the
            // 31st afterwards instead of drifting to the 28th for good.
            $table->date('start_date');
            $table->date('next_occurrence_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['next_occurrence_date']);
            $table->index(['user_id']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('recurring_rule_id')->nullable()->after('recurrence_parent_id')
                ->constrained('recurring_rules')->nullOnDelete();
            // sha256 of (account, date, signed cents, normalised description, nth identical row in the file).
            $table->string('import_fingerprint', 64)->nullable();
            $table->string('import_batch_id', 36)->nullable()->index();
            $table->unique(['user_id', 'import_fingerprint']);
        });

        Schema::create('finance_audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('action', 40);
            $table->string('actor', 10)->default('user'); // user | system
            $table->string('subject_type', 30);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable();
            // Lets amounts in 'changes' be shown with the right currency.
            $table->string('currency', 3)->nullable();
            // {field: {before, after}} for non-sensitive fields; {field: {changed: true}} for free text.
            $table->json('changes')->nullable();
            $table->string('context')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        (new LegacyRecurrenceBackfill)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_audit_log');

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'import_fingerprint']);
            $table->dropIndex(['import_batch_id']);
            $table->dropConstrainedForeignId('recurring_rule_id');
            $table->dropColumn(['import_fingerprint', 'import_batch_id']);
        });

        Schema::dropIfExists('recurring_rules');

        Schema::table('budgets', function (Blueprint $table) {
            $table->dropIndex('budgets_period_lookup_index');
            $table->unique(['user_id', 'category_id', 'currency', 'month', 'year']);
        });

        foreach (['accounts', 'categories', 'budgets', 'transactions'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
