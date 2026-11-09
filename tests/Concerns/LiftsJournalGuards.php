<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Lets a test corrupt the books on purpose (to prove the verifier notices) by lifting the append-only and
 * balance triggers for the rest of the test's own transaction. The transaction is rolled back at the end of
 * the test, which restores the triggers on both engines (DDL is transactional on SQLite and PostgreSQL).
 */
trait LiftsJournalGuards
{
    protected function liftJournalGuards(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (['journal_lines_no_update', 'journal_lines_no_delete', 'journal_entries_no_delete', 'journal_entries_guard_update'] as $trigger) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            }

            return;
        }

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE'); // flush pending deferred trigger events first, or ALTER TABLE is refused
        DB::unprepared('ALTER TABLE journal_lines DISABLE TRIGGER USER');
        DB::unprepared('ALTER TABLE journal_entries DISABLE TRIGGER USER');
    }
}
