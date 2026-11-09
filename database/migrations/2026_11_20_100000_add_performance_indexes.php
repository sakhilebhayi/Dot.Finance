<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * R-44 performance QA (docs/R44_QA_perf.md): three indexes found by running EXPLAIN (ANALYZE, BUFFERS) on a ledger of about
 * 195,000 cash rows, 245,000 journal entries and 540,000 journal lines.
 *
 *   sales_documents_open_due_idx      the open invoices by due date (overdue count on every page, reminders, ageing): 47 ms -> 0.2 ms,
 *                                     and 630 ms -> 0.2 ms when the table is not in the cache
 *   transactions_balance_cover_idx    an account's balance is summed from its rows; with the amount, type and direction in the
 *                                     index the sum never touches the table: 100 ms -> 38 ms for five accounts of 39,000 rows
 *   journal_lines_control_cover_idx   what each customer or supplier owes, summed from the control account: 138 ms -> 8 ms
 *
 * Idempotent (IF NOT EXISTS) and safe to run on a live PostgreSQL: the indexes are built CONCURRENTLY, so writes are not blocked
 * while they build, which means this migration runs outside a transaction. A concurrent build that was interrupted leaves an
 * INVALID index behind; it is dropped and built again here. SQLite has no INCLUDE columns: it gets the partial index only.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** @var array<string, array{table: string, columns: string, include: ?string, where: ?string}> */
    private const INDEXES = [
        'sales_documents_open_due_idx' => ['table' => 'sales_documents', 'columns' => 'team_id, due_date', 'include' => null, 'where' => "kind IN ('tax_invoice', 'debit_note') AND status IN ('issued', 'partially_paid')"],
        'sales_documents_open_due_user_idx' => ['table' => 'sales_documents', 'columns' => 'user_id, due_date', 'include' => null, 'where' => "kind IN ('tax_invoice', 'debit_note') AND status IN ('issued', 'partially_paid')"],
        'transactions_balance_cover_idx' => ['table' => 'transactions', 'columns' => 'account_id, date', 'include' => 'type, amount_cents, transfer_direction', 'where' => 'deleted_at IS NULL'],
        'journal_lines_control_cover_idx' => ['table' => 'journal_lines', 'columns' => 'gl_account_id, party_id', 'include' => 'team_id, user_id, debit_cents, credit_cents', 'where' => null],
    ];

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            return;
        }

        foreach (self::INDEXES as $name => $spec) {
            if ($driver === 'sqlite' && $spec['include'] !== null) {
                continue;
            }
            if ($driver === 'pgsql') {
                // an interrupted CONCURRENTLY build leaves an invalid index that IF NOT EXISTS would then skip
                $invalid = DB::selectOne('SELECT 1 AS x FROM pg_class c JOIN pg_index i ON i.indexrelid = c.oid WHERE c.relname = ? AND NOT i.indisvalid', [$name]);
                if ($invalid !== null) {
                    DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
                }
            }

            $sql = 'CREATE INDEX '.($driver === 'pgsql' ? 'CONCURRENTLY ' : '')."IF NOT EXISTS {$name} ON {$spec['table']} ({$spec['columns']})"
                .($spec['include'] !== null ? " INCLUDE ({$spec['include']})" : '')
                .($spec['where'] !== null ? " WHERE {$spec['where']}" : '');
            DB::statement($sql);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement('DROP INDEX '.(DB::connection()->getDriverName() === 'pgsql' ? 'CONCURRENTLY ' : '')."IF EXISTS {$name}");
        }
    }
};
