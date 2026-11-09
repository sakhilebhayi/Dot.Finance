<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL does not short-circuit AND, so journal_rows_immutable() read OLD.status on a journal_lines row (which has no such
 * column) before it ever got to the "append-only" refusal. The update was still refused, but with "record old has no field
 * status" (SQLSTATE 55000) instead of the intended integrity_constraint_violation (23000) and message. The check on the
 * table name now sits in an outer IF, so only a journal_entries row reaches the status test. Behaviour is otherwise unchanged:
 * the one permitted update is posted -> reversed with the reversal link, nothing else on either table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION journal_rows_immutable() RETURNS trigger AS $$
BEGIN
    IF TG_TABLE_NAME = 'journal_entries' AND TG_OP = 'UPDATE' THEN
        IF OLD.status = 'posted' AND NEW.status = 'reversed'
           AND OLD.reversed_by_entry_id IS NULL AND NEW.reversed_by_entry_id IS NOT NULL
           AND (to_jsonb(NEW) - 'status' - 'reversed_by_entry_id') = (to_jsonb(OLD) - 'status' - 'reversed_by_entry_id') THEN
            RETURN NEW;
        END IF;
    END IF;

    RAISE EXCEPTION 'journal rows are append-only: % on % is not allowed (post a reversing entry instead)', TG_OP, TG_TABLE_NAME
        USING ERRCODE = 'integrity_constraint_violation';
END;
$$ LANGUAGE plpgsql
SQL);
    }

    public function down(): void
    {
        // the previous body was the defect; there is nothing to go back to
    }
};
