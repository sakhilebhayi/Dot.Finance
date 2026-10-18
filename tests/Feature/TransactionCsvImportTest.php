<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class TransactionCsvImportTest extends TestCase
{
    use RefreshDatabase;

    protected function makeAccount(User $user, array $overrides = []): Account
    {
        return Account::create(array_merge([
            'user_id' => $user->id,
            'name' => 'Main Checking',
            'type' => 'checking',
            'currency' => 'ZAR',
            'opening_balance_cents' => 100000,
            'institution' => 'Test Bank',
        ], $overrides));
    }

    private function csvFile(string $contents, string $name = 'statement.csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    public function test_guests_cannot_access_the_import_form(): void
    {
        $this->get(route('transactions.import.create'))->assertRedirect(route('login'));
    }

    public function test_preview_parses_a_well_formed_csv(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = $this->makeAccount($user);

        $csv = "date,description,amount\n2026-08-01,Grocery Store,-45.50\n2026-08-02,Paycheck,1500.00\n";

        $response = $this->actingAs($user)->post(route('transactions.import.preview'), [
            'account_id' => $account->id,
            'file' => $this->csvFile($csv),
        ]);

        $response->assertOk();
        $response->assertSee('Grocery Store');
        $response->assertSee('Paycheck');
        $response->assertSee('2 of 2');
    }

    public function test_a_negative_amount_is_parsed_as_an_expense_with_a_positive_magnitude(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = $this->makeAccount($user);

        $csv = "date,description,amount\n2026-08-01,Grocery Store,-45.50\n";

        $response = $this->actingAs($user)->post(route('transactions.import.preview'), [
            'account_id' => $account->id,
            'file' => $this->csvFile($csv),
        ]);

        $response->assertViewHas('rows', function ($rows) {
            return $rows[0]['amount'] === '45.50' && $rows[0]['type'] === 'expense';
        });
    }

    public function test_a_row_with_an_invalid_amount_is_flagged_but_still_shown(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = $this->makeAccount($user);

        $csv = "date,description,amount\n2026-08-01,Mystery charge,not-a-number\n";

        $response = $this->actingAs($user)->post(route('transactions.import.preview'), [
            'account_id' => $account->id,
            'file' => $this->csvFile($csv),
        ]);

        $response->assertViewHas('rows', fn ($rows) => $rows[0]['error'] !== null);
        $response->assertViewHas('validCount', 0);
    }

    public function test_a_category_column_matches_an_existing_category_by_name(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = $this->makeAccount($user);
        $groceries = Category::create(['user_id' => $user->id, 'name' => 'Groceries', 'color' => '#6366f1', 'type' => 'expense']);

        $csv = "date,description,amount,category\n2026-08-01,Grocery Store,-45.50,groceries\n";

        $response = $this->actingAs($user)->post(route('transactions.import.preview'), [
            'account_id' => $account->id,
            'file' => $this->csvFile($csv),
        ]);

        $response->assertViewHas('rows', fn ($rows) => $rows[0]['category_id'] === $groceries->id);
    }

    public function test_store_creates_only_the_checked_rows(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = $this->makeAccount($user);

        $response = $this->actingAs($user)->post(route('transactions.import.store'), [
            'account_id' => $account->id,
            'rows' => [
                ['include' => '1', 'description' => 'Grocery Store', 'amount' => '45.50', 'type' => 'expense', 'date' => '2026-08-01'],
                ['include' => '0', 'description' => 'Skip me', 'amount' => '10.00', 'type' => 'expense', 'date' => '2026-08-02'],
            ],
        ]);

        $response->assertRedirect(route('transactions.index'));
        $this->assertDatabaseHas('transactions', ['description' => 'Grocery Store', 'user_id' => $user->id]);
        $this->assertDatabaseMissing('transactions', ['description' => 'Skip me']);
        $this->assertSame(1, Transaction::count());
    }

    public function test_store_attaches_every_imported_row_to_the_chosen_account(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = $this->makeAccount($user);

        $this->actingAs($user)->post(route('transactions.import.store'), [
            'account_id' => $account->id,
            'rows' => [
                ['include' => '1', 'description' => 'Row A', 'amount' => '10.00', 'type' => 'expense', 'date' => '2026-08-01'],
                ['include' => '1', 'description' => 'Row B', 'amount' => '20.00', 'type' => 'income', 'date' => '2026-08-02'],
            ],
        ]);

        $this->assertSame(2, Transaction::where('account_id', $account->id)->count());
    }

    public function test_a_user_cannot_import_against_another_users_account(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $other = User::factory()->withPersonalTeam()->create();
        $otherAccount = $this->makeAccount($other);

        $response = $this->actingAs($user)->post(route('transactions.import.store'), [
            'account_id' => $otherAccount->id,
            'rows' => [
                ['include' => '1', 'description' => 'Sneaky', 'amount' => '10.00', 'type' => 'expense', 'date' => '2026-08-01'],
            ],
        ]);

        $response->assertSessionHasErrors('account_id');
        $this->assertDatabaseMissing('transactions', ['description' => 'Sneaky']);
    }

    public function test_a_user_cannot_assign_an_imported_row_to_another_users_category(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = $this->makeAccount($user);
        $other = User::factory()->withPersonalTeam()->create();
        $otherCategory = Category::create(['user_id' => $other->id, 'name' => 'Other', 'color' => '#6366f1', 'type' => 'expense']);

        $response = $this->actingAs($user)->post(route('transactions.import.store'), [
            'account_id' => $account->id,
            'rows' => [
                ['include' => '1', 'description' => 'Sneaky', 'amount' => '10.00', 'type' => 'expense', 'date' => '2026-08-01', 'category_id' => $otherCategory->id],
            ],
        ]);

        $response->assertSessionHasErrors('rows.0.category_id');
        $this->assertDatabaseMissing('transactions', ['description' => 'Sneaky']);
    }
}
