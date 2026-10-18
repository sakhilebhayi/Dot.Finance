<?php

namespace Tests\Feature\Company;

use App\Models\Concerns\HasLedgerContext;
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
                $this->assertContains(HasLedgerContext::class, class_uses_recursive($class), "{$class} has user_id and team_id columns, so it must use HasLedgerContext");
                $checked++;
            }
        }

        $this->assertSame(7, $checked, 'accounts, categories, budgets, transactions, recurring_rules, finance_audit_log and ai_insights');
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
            'Models/Team.php' => 'asks whether a company has any ledger rows before it can be deleted',
            'Models/Transaction.php' => 'reads an account currency and releases an import fingerprint by id',
            'Models/RecurringRule.php' => 'reads the currency of the account it points at',
            'Support/Ledger/LedgerOwnership.php' => 'reads the owner of a referenced record to check it is in the same context',
            'Support/Ledger/LedgerContext.php' => 'lists which contexts own rows, as ids only, for the scheduler and weekly job',
            'Services/LedgerReconciler.php' => 'reads one ledger at a time to find rows that point across ledgers',
            'Services/LegacyRecurrenceBackfill.php' => 'one-off legacy conversion run by a migration',
            'Services/LegacyMoneyBackfill.php' => 'one-off legacy conversion run by a migration',
            'Models/Builders/AppendOnlyBuilder.php' => 'a comment about why raw access cannot be guarded',
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
