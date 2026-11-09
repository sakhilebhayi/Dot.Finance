<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the report queries (R-44 Reporting). Every report reads journal_lines by ledger and date range and sums the
 * amounts per account; the existing (gl_account_id, entry_date) index cannot serve that, so the planner scans the whole table.
 * On PostgreSQL these are covering indexes (the sums are answered from the index alone); SQLite, which the tests use, gets
 * plain composite ones. The measurements that justified them are in docs/R44_REPORTING.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX IF NOT EXISTS journal_lines_team_date_cover ON journal_lines (team_id, entry_date) INCLUDE (gl_account_id, debit_cents, credit_cents) WHERE team_id IS NOT NULL');
            DB::statement('CREATE INDEX IF NOT EXISTS journal_lines_user_date_cover ON journal_lines (user_id, entry_date) INCLUDE (gl_account_id, debit_cents, credit_cents) WHERE team_id IS NULL');
            // The party-level views (ageing, revenue by customer) filter the receivable or payable account by party.
            DB::statement('CREATE INDEX IF NOT EXISTS journal_lines_account_party_date ON journal_lines (gl_account_id, party_id, entry_date) WHERE party_id IS NOT NULL');

            return;
        }

        Schema::table('journal_lines', function ($table) {
            $table->index(['team_id', 'entry_date', 'gl_account_id'], 'journal_lines_team_date_account');
            $table->index(['user_id', 'entry_date', 'gl_account_id'], 'journal_lines_user_date_account');
            $table->index(['gl_account_id', 'party_id', 'entry_date'], 'journal_lines_account_party_date');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS journal_lines_team_date_cover');
            DB::statement('DROP INDEX IF EXISTS journal_lines_user_date_cover');
            DB::statement('DROP INDEX IF EXISTS journal_lines_account_party_date');

            return;
        }

        Schema::table('journal_lines', function ($table) {
            $table->dropIndex('journal_lines_team_date_account');
            $table->dropIndex('journal_lines_user_date_account');
            $table->dropIndex('journal_lines_account_party_date');
        });
    }
};
