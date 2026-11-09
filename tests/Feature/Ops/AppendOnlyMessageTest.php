<?php

namespace Tests\Feature\Ops;

use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\PostingLine;
use App\Services\Accounting\PostingRequest;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsAccounting;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\TestCase;

/** Posted journal rows refuse change with the intended message (the PostgreSQL trigger once failed on a missing column instead). */
class AppendOnlyMessageTest extends TestCase
{
    use BuildsAccounting;
    use BuildsCompanyLedgers;
    use RefreshDatabase;

    public function test_changing_or_deleting_a_posted_line_is_refused_as_append_only(): void
    {
        $user = $this->user();
        $ctx = $this->personalContext($user);
        $this->enableAccounting($ctx);
        $entry = LedgerContext::run($ctx, fn () => app(JournalPoster::class)->post(new PostingRequest(CarbonImmutable::parse('2026-03-05'), 'manual', 'append-only', 1, 'Append only', [
            new PostingLine('bank', debitCents: 1000), new PostingLine('sales', creditCents: 1000),
        ])));

        foreach ([
            fn () => DB::table('journal_lines')->where('journal_entry_id', $entry->id)->update(['debit_cents' => 1]),
            fn () => DB::table('journal_lines')->where('journal_entry_id', $entry->id)->delete(),
            fn () => DB::table('journal_entries')->where('id', $entry->id)->update(['memo' => 'changed']),
            fn () => DB::table('journal_entries')->where('id', $entry->id)->delete(),
        ] as $attempt) {
            try {
                DB::transaction($attempt); // a savepoint: PostgreSQL aborts the whole transaction on an error
                $this->fail('a posted journal row was changed');
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage(), 'refused for the right reason');
                if (DB::connection()->getDriverName() === 'pgsql') {
                    $this->assertStringContainsString('SQLSTATE[23000]', $e->getMessage(), 'as an integrity violation, on every journal table');
                }
            }
        }
    }
}
