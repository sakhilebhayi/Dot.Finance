<?php

namespace Tests\Feature\Integration;

use App\Actions\Jetstream\DeleteTeam;
use App\Actions\Jetstream\DeleteUser;
use App\Models\Account;
use App\Models\Bank\BankStatement;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\Concerns\BuildsTaxFixtures;
use Tests\TestCase;

/**
 * R-44 integration: deleting a company or a person cannot orphan or bypass what the modules keep. The lists the deletion guards read
 * are checked against the database schema itself, so a table added by any future module that restricts deletion but is not on the
 * list fails here instead of failing in front of a user.
 */
class LedgerTablesTest extends TestCase
{
    use BuildsCompanyLedgers;
    use BuildsTaxFixtures;
    use RefreshDatabase;

    /** @return list<string> tables whose foreign key on $column restricts (or refuses by default) the delete of the parent row */
    private function restrictingTables(string $column, string $parent): array
    {
        $found = [];
        foreach (Schema::getTables() as $table) {
            foreach (Schema::getForeignKeys($table['name']) as $fk) {
                if ($fk['columns'] === [$column] && $fk['foreign_table'] === $parent && in_array(strtolower((string) $fk['on_delete']), ['restrict', 'no action'], true)) {
                    $found[] = $table['name'];
                }
            }
        }
        sort($found);

        return $found;
    }

    public function test_every_table_that_restricts_deleting_a_company_is_on_the_company_data_list(): void
    {
        $listed = [...Team::LEDGER_TABLES, ...Team::SHARED_DATA_TABLES];
        $restricting = $this->restrictingTables('team_id', 'teams');

        $this->assertNotEmpty($restricting);
        $this->assertSame([], array_values(array_diff($restricting, $listed)), 'These tables hold company rows and refuse the company being deleted, but Team::hasLedgerData() does not look at them');
        $this->assertSame([], array_values(array_diff($listed, array_map(fn ($t) => $t['name'], Schema::getTables()))), 'These listed tables do not exist');
        $this->assertSame([], array_values(array_diff($listed, $restricting)), 'These listed tables do not restrict deleting a company, so listing them would block a delete the database allows');
        $this->assertSame(array_values(array_unique($listed)), $listed, 'a table is listed twice');
    }

    public function test_every_table_that_restricts_deleting_a_person_is_on_the_personal_data_list(): void
    {
        $restricting = $this->restrictingTables('user_id', 'users');

        $this->assertSame($restricting, collect(Team::USER_RESTRICTED_TABLES)->sort()->values()->all());
    }

    public function test_a_company_with_only_a_tax_profile_cannot_be_deleted_and_nothing_is_lost(): void
    {
        $this->taxLedger();
        $this->vatProfile();

        $this->assertTrue($this->acme->hasLedgerData());
        $this->assertSame(0, DB::table('transactions')->where('team_id', $this->acme->id)->count());

        try {
            app(DeleteTeam::class)->delete($this->acme);
            $this->fail('the company has books');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('team', $e->errors());
        }

        $this->assertNotNull(Team::find($this->acme->id));
        $this->assertSame(1, DB::table('tax_profiles')->where('team_id', $this->acme->id)->count());
    }

    public function test_a_company_with_only_a_bank_statement_cannot_be_deleted(): void
    {
        $owner = $this->user();
        $company = $this->company($owner, 'Statement Co');
        $account = $this->companyAccount($company, ['currency' => 'ZAR']);
        BankStatement::create($account->ledgerOwner() + [
            'account_id' => $account->id, 'source' => 'csv', 'original_name' => 's.csv', 'file_sha256' => hash('sha256', Str::uuid()->toString()),
            'currency' => 'ZAR', 'date_from' => '2026-10-01', 'date_to' => '2026-10-31', 'line_count' => 0, 'duplicate_count' => 0,
        ]);
        DB::table('accounts')->where('id', $account->id)->update(['deleted_at' => now()]);

        $this->assertTrue($company->hasLedgerData());
        $this->expectException(ValidationException::class);
        app(DeleteTeam::class)->delete($company);
    }

    public function test_a_person_whose_personal_books_hold_bank_statements_is_refused_in_words_and_kept(): void
    {
        $user = $this->user();
        $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'ZAR']);
        BankStatement::create($account->ledgerOwner() + [
            'account_id' => $account->id, 'source' => 'csv', 'original_name' => 's.csv', 'file_sha256' => hash('sha256', Str::uuid()->toString()),
            'currency' => 'ZAR', 'date_from' => '2026-10-01', 'date_to' => '2026-10-31', 'line_count' => 0, 'duplicate_count' => 0,
        ]);

        try {
            app(DeleteUser::class)->delete($user);
            $this->fail('the person has records that are kept');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('password', $e->errors());
            $this->assertStringContainsString('not deleted along with a person', $e->errors()['password'][0]);
        }

        $this->assertNotNull(User::find($user->id));
        $this->assertSame(1, DB::table('bank_statements')->where('user_id', $user->id)->count());
    }

    public function test_a_person_with_nothing_to_keep_can_still_be_deleted(): void
    {
        $user = $this->user();

        app(DeleteUser::class)->delete($user);

        $this->assertNull(User::find($user->id));
    }
}
