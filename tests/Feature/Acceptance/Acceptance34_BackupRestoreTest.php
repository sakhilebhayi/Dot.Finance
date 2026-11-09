<?php

namespace Tests\Feature\Acceptance;

use Illuminate\Support\Facades\DB;
use Tests\Support\BackupRestoreDrill;
use Tests\TestCase;

/**
 * Owner criterion 34: backup restoration is demonstrated. A real ledger (the demo company with a sales history, accounting on) is
 * built in a throwaway database, backed up (pg_dump -Fc on PostgreSQL; a consistent VACUUM INTO copy on SQLite), restored into a
 * second empty database, and the restored books must verify with the application's own `finance:gl:verify` and carry the same counts,
 * totals and last journal hash as the source. Then one cent is changed in the restored copy to show the verifier would notice.
 * See tests/Support/BackupRestoreDrill.php; the full runbook (schedule, retention, off-site, timings at volume) is the performance
 * agent's work (docs/R44_QA_perf.md and scripts/ops, not in this branch's base).
 *
 * Not RefreshDatabase: the drill uses its own databases through child processes and never touches the suite's.
 */
class Acceptance34_BackupRestoreTest extends TestCase
{
    public function test_a_backup_is_restored_into_a_new_database_and_the_restored_books_verify_and_match(): void
    {
        $driver = DB::getDriverName();
        if (! BackupRestoreDrill::available($driver)) {
            $this->markTestSkipped("The {$driver} backup tools are not installed here.");
        }

        $result = (new BackupRestoreDrill($driver))->run_();

        $this->assertSame($result['source']['counts'], $result['restored']['counts'], 'every table has the same number of rows after the restore');
        $this->assertGreaterThan(50, $result['source']['counts']['journal_lines'], 'a real ledger, not an empty one');
        $this->assertGreaterThan(0, $result['source']['counts']['sales_documents']);
        $this->assertSame($result['source']['debits'], $result['restored']['debits']);
        $this->assertSame($result['source']['credits'], $result['restored']['credits']);
        $this->assertSame($result['source']['debits'], $result['source']['credits'], 'and the restored journals balance');
        $this->assertSame($result['source']['last_entry_hash'], $result['restored']['last_entry_hash'], 'the hash chain ends where it did');
        $this->assertSame($result['source']['invoice_total_cents'], $result['restored']['invoice_total_cents']);
        $this->assertTrue($result['tampered_detected'], 'a one-cent change in the restored copy is caught by finance:gl:verify');
        fwrite(STDERR, "\n[drill {$driver}] ".json_encode(['seconds' => $result['seconds'], 'backup_bytes' => $result['backup_bytes'], 'journal_lines' => $result['source']['counts']['journal_lines'], 'entries' => $result['source']['counts']['journal_entries']])."\n");
    }
}
