<?php

namespace Tests\Feature\Company;

use App\Models\Concerns\HasLedgerContext;
use App\Models\Concerns\HasOptionalLedgerContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tripwires against the scope being quietly bypassed later: a new ledger model that forgets
 * the shared scope, or new code that reaches around it.
 */
class LedgerScopeGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_model_whose_table_can_hold_company_rows_carries_the_shared_scope(): void
    {
        $checked = 0;

        foreach (File::allFiles(app_path('Models')) as $file) {
            $class = 'App\\Models\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (! class_exists($class) || ! is_subclass_of($class, Model::class) || (new \ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $table = (new $class)->getTable();

            if (Schema::hasColumn($table, 'team_id') && Schema::hasColumn($table, 'user_id') && $table !== 'team_user') {
                // R-44: reference data that is global by default and may be overridden per ledger (tax codes, rules,
                // rates, FX rates) uses HasOptionalLedgerContext, whose scope shows global rows plus the active
                // ledger's own and never another ledger's. Everything else must use HasLedgerContext.
                $uses = class_uses_recursive($class);
                $this->assertTrue(
                    in_array(HasLedgerContext::class, $uses, true) || in_array(HasOptionalLedgerContext::class, $uses, true),
                    "{$class} has user_id and team_id columns, so it must use HasLedgerContext (or HasOptionalLedgerContext for global reference data)",
                );
                $checked++;
            }
        }

        // The original seven (accounts, categories, budgets, transactions, recurring_rules, finance_audit_log and
        // ai_insights) are a floor: R-44 adds ledger models in parallel, and the loop above checks every one of them.
        $this->assertGreaterThanOrEqual(7, $checked);
    }

    public function test_nothing_in_app_reaches_around_the_scope_with_a_global_scope_bypass(): void
    {
        $offenders = [];

        foreach (File::allFiles(app_path()) as $file) {
            if (preg_match('/withoutGlobalScopes?\(/', $file->getContents()) === 1) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        // The only sanctioned way past the scope is HasLedgerContext::scopeForContext, which names a context.
        $this->assertSame(['Models/Concerns/HasLedgerContext.php'], $offenders);
    }

    public function test_raw_table_access_is_limited_to_the_files_that_have_a_reason_for_it(): void
    {
        $allowed = [
            'Models/Team.php' => 'asks whether a company, or a person\'s personal ledger, has any rows that restrict deleting it',
            'Models/Transaction.php' => 'reads an account currency and releases an import fingerprint by id',
            'Models/RecurringRule.php' => 'reads the currency of the account it points at',
            'Support/Ledger/LedgerOwnership.php' => 'reads the owner of a referenced record to check it is in the same context',
            'Support/Ledger/LedgerContext.php' => 'lists which contexts own rows, as ids only, for the scheduler and weekly job',
            'Services/LedgerReconciler.php' => 'reads one ledger at a time to find rows that point across ledgers',
            'Services/LegacyRecurrenceBackfill.php' => 'one-off legacy conversion run by a migration',
            'Services/LegacyMoneyBackfill.php' => 'one-off legacy conversion run by a migration',
            'Services/Controls/AccessReport.php' => 'reads the live sessions table for "last seen" (no model exists for it, and it holds no ledger rows)',
            'Models/Builders/AppendOnlyBuilder.php' => 'a comment about why raw access cannot be guarded',
            'Console/Commands/EnsureRecurringIndex.php' => 'one-off schema repair: looks for duplicate recurring rows across all ledgers',
            'Services/Accounting/GlBackfill.php' => 'counts the journal rows of the one ledger it was handed, with an explicit owner condition',
            'Services/Accounting/GlQuery.php' => 'set-based reporting over journal lines; every query carries an explicit owner condition (JournalIsolationTest proves it)',
            'Services/Accounting/GlSweeper.php' => 'set-based search for unposted rows in the one ledger it was handed, with an explicit owner condition',
            'Services/Accounting/GlVerifier.php' => 'integrity checks over the one ledger it was handed, with an explicit owner condition',
            'Services/Accounting/JournalPoster.php' => 'bulk insert of journal lines and the single guarded reversal-link update (append-only rows have no model path for either)',
            'Services/Sales/ShareLinks.php' => 'finds a customer link by the hash of its secret token to learn which ledger it belongs to, before any scoped model can be read (reads owner columns only)',
            'Console/Commands/SeedReferenceData.php' => 'counts the rows of the reference-data tables (tax rules, rates, codes, holidays) before and after seeding: they hold no ledger rows of their own to leak',
            'Services/Accounting/Periods.php' => 'year-end aggregate over the one ledger it was handed, with an explicit owner condition',
            // R-44 Bank: every one of these keys its set-based read or write by ids first loaded through a scoped model in the same
            // transaction (the session, the account, the statement, the match), or states the owner condition itself (BankIsolationTest proves it).
            'Services/Bank/StatementImporter.php' => 'chunked insert of statement lines for the one account it was handed (owner copied from that account) and a held-fingerprint lookup bounded by that account',
            'Services/Bank/MatchingService.php' => 'chunked insert of matches and CASE links of lines and rows already loaded and locked through scoped queries for one session',
            'Services/Bank/MatchRecorder.php' => 'links the lines and rows of one match inside a locked session',
            'Services/Bank/ReconciliationService.php' => 'releases or locks the rows of one session (ids read through the scoped session and its matches)',
            'Services/Bank/ReconciliationReport.php' => 'counts the rows of one session by match id',
            'Services/Bank/ReconciliationBalances.php' => 'journal balance of one cash account with an explicit owner condition',
            'Services/Bank/ExceptionClassifier.php' => 'reads held lines of the session account and of the ledger\'s other accounts (ids from scoped Account queries)',
            'Services/Bank/LineActions.php' => 'bumps the use counter of one bank rule already loaded through a scoped model',
            'Http/Controllers/Bank/StatementController.php' => 'existence checks on the lines of one statement the route already bound through the scope',
            'Http/Controllers/Bank/ReconciliationController.php' => 'lists the lines and rows of matches that were loaded through the scoped session',
        ];

        $found = [];
        foreach (File::allFiles(app_path()) as $file) {
            if (str_contains($file->getContents(), 'DB::table(')) {
                $found[] = $file->getRelativePathname();
            }
        }

        sort($found);
        $expected = array_keys($allowed);
        sort($expected);

        $this->assertSame($expected, $found, 'A file now uses DB::table(): add it here only with a reason it cannot go through a scoped model');
    }

    public function test_the_old_per_user_scope_is_gone(): void
    {
        $this->assertFileDoesNotExist(app_path('Models/Concerns/HasUserScope.php'));
        $this->assertFalse(trait_exists('App\\Models\\Concerns\\HasUserScope'));
    }
}
