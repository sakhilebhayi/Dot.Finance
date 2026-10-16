<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // null recurrence_type = a normal, one-off transaction (the
            // pre-existing default). Set on the *anchor* transaction the
            // user marked recurring; every generated occurrence carries
            // the same value too, purely so the UI can badge it, but only
            // the anchor (recurrence_parent_id null) is ever scheduled --
            // see TransactionRecurrenceService.
            $table->enum('recurrence_type', ['daily', 'weekly', 'monthly', 'yearly'])
                ->nullable()->after('notes');
            $table->unsignedInteger('recurrence_interval')->default(1)->after('recurrence_type');

            // Only meaningful on an anchor: the next date an occurrence is
            // due. Advanced by the scheduler each time it generates one --
            // never read on a generated occurrence itself.
            $table->date('next_occurrence_date')->nullable()->after('recurrence_interval');

            // Points at the original transaction in a recurring series
            // (null on that original itself) -- never a subtask/parent
            // ledger relationship, purely provenance for "which series did
            // this row come from."
            $table->foreignId('recurrence_parent_id')->nullable()->after('next_occurrence_date')
                ->constrained('transactions')->nullOnDelete();

            $table->index(['recurrence_type', 'next_occurrence_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recurrence_parent_id');
            $table->dropColumn(['recurrence_type', 'recurrence_interval', 'next_occurrence_date']);
        });
    }
};
