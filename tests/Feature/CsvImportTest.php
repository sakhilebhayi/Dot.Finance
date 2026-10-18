<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\FinanceAuditLog;
use App\Models\Transaction;
use App\Models\User;
use App\Support\ImportFingerprint;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CsvImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function csv(string $contents, string $name = 'statement.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents);
    }

    /** Upload a CSV and return the preview token. */
    private function upload(User $user, Account $account, string $contents, string $name = 'statement.csv'): string
    {
        $response = $this->actingAs($user)->post(route('transactions.import.upload'), [
            'account_id' => $account->id,
            'file' => $this->csv($contents, $name),
        ]);
        $response->assertSessionHasNoErrors();
        $location = $response->headers->get('Location');
        $this->assertNotNull($location, 'upload should redirect to the preview');

        return basename(parse_url($location, PHP_URL_PATH));
    }

    private function preview(User $user, string $token, array $query = [])
    {
        return $this->actingAs($user)->get(route('transactions.import.preview', ['token' => $token] + $query));
    }

    private function store(User $user, string $token, array $overrides = [])
    {
        return $this->actingAs($user)->post(route('transactions.import.store'), array_merge([
            'token' => $token, 'date_format' => 'ymd', 'amount_locale' => 'en',
        ], $overrides));
    }

    private function fixture(): array
    {
        $user = User::factory()->withPersonalTeam()->create();
        $account = Account::factory()->for($user)->opening(100_000, null)->create();

        return [$user, $account];
    }

    // ── upload / preview ───────────────────────────────────────────────────

    public function test_guests_cannot_reach_the_import(): void
    {
        $this->get(route('transactions.import.create'))->assertRedirect(route('login'));
        $this->post(route('transactions.import.upload'))->assertRedirect(route('login'));
    }

    public function test_the_upload_form_renders(): void
    {
        [$user] = $this->fixture();

        $this->actingAs($user)->get(route('transactions.import.create'))->assertOk()->assertSee('Import Transactions');
    }

    public function test_upload_stages_the_file_and_redirects_to_a_preview(): void
    {
        [$user, $account] = $this->fixture();

        $token = $this->upload($user, $account, "date,description,amount\n2026-08-01,Grocery Store,-45.50\n");

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $token);
        Storage::disk('local')->assertExists("finance-imports/{$user->id}/{$token}.csv");
        $this->assertSame(0, Transaction::count(), 'uploading must not write any transactions');
    }

    public function test_an_iso_file_previews_with_its_format_detected(): void
    {
        [$user, $account] = $this->fixture();
        $token = $this->upload($user, $account, "date,description,amount\n2026-08-01,Grocery Store,-45.50\n2026-08-02,Paycheck,1500.00\n");

        $this->preview($user, $token)->assertOk()
            ->assertSee('Grocery Store')
            ->assertSee('Paycheck')
            ->assertViewHas('preview', function (array $p) {
                return $p['detected_date_format'] === 'ymd' && $p['date_ambiguous'] === false && $p['ready'] === true
                    && $p['counts']['importable'] === 2
                    && $p['rows'][0]['cents'] === -4_550 && $p['rows'][0]['type'] === 'expense' && $p['rows'][0]['date'] === '2026-08-01'
                    && $p['rows'][1]['cents'] === 150_000 && $p['rows'][1]['type'] === 'income';
            });
    }

    public function test_ambiguous_dates_are_not_guessed_and_the_user_must_choose(): void
    {
        [$user, $account] = $this->fixture();
        $token = $this->upload($user, $account, "date,description,amount\n03/04/2026,Ambiguous coffee,-10.00\n05/06/2026,Ambiguous lunch,-20.00\n");

        $this->preview($user, $token)->assertOk()
            ->assertSee('can be read two ways')
            ->assertDontSee('Import selected rows')
            ->assertViewHas('preview', fn (array $p) => $p['detected_date_format'] === null && $p['date_ambiguous'] === true
                && $p['ready'] === false && $p['rows'][0]['date'] === null && $p['counts']['importable'] === 0);

        $this->preview($user, $token, ['date_format' => 'dmy'])->assertOk()
            ->assertSee('Import selected rows')
            ->assertViewHas('preview', fn (array $p) => $p['rows'][0]['date'] === '2026-04-03' && $p['rows'][1]['date'] === '2026-06-05' && $p['ready'] === true);

        $this->preview($user, $token, ['date_format' => 'mdy'])->assertOk()
            ->assertViewHas('preview', fn (array $p) => $p['rows'][0]['date'] === '2026-03-04' && $p['rows'][1]['date'] === '2026-05-06');
    }

    public function test_a_day_above_twelve_settles_the_format(): void
    {
        [$user, $account] = $this->fixture();
        $token = $this->upload($user, $account, "date,description,amount\n25/12/2026,Christmas,-10.00\n03/04/2026,April,-20.00\n");

        $this->preview($user, $token)->assertViewHas('preview', fn (array $p) => $p['detected_date_format'] === 'dmy'
            && $p['rows'][1]['date'] === '2026-04-03');
    }

    public function test_a_chosen_format_that_contradicts_the_data_flags_the_rows_instead_of_inventing_dates(): void
    {
        [$user, $account] = $this->fixture();
        $token = $this->upload($user, $account, "date,description,amount\n25/12/2026,Christmas,-10.00\n");

        $this->preview($user, $token, ['date_format' => 'mdy'])->assertViewHas('preview', fn (array $p) => $p['rows'][0]['date'] === null
            && str_contains((string) $p['rows'][0]['error'], 'date') && $p['counts']['importable'] === 0);
    }

    public function test_european_amounts_parse_per_the_chosen_locale(): void
    {
        [$user, $account] = $this->fixture();
        $token = $this->upload($user, $account, "date;description;amount\n2026-08-01;Rent;-1 234,56\n2026-08-02;Salary;12 000,00\n");

        $this->preview($user, $token)->assertViewHas('preview', fn (array $p) => $p['detected_amount_locale'] === 'eu'
            && $p['rows'][0]['cents'] === -123_456 && $p['rows'][1]['cents'] === 1_200_000);
    }

    public function test_an_ambiguous_amount_column_asks_instead_of_guessing(): void
    {
        [$user, $account] = $this->fixture();
        $token = $this->upload($user, $account, "date,description,amount\n2026-08-01,Lump sum,\"1,234\"\n");

        $this->preview($user, $token)->assertViewHas('preview', fn (array $p) => $p['amount_ambiguous'] === true && $p['ready'] === false);

        $this->preview($user, $token, ['amount_locale' => 'en'])->assertViewHas('preview', fn (array $p) => $p['rows'][0]['cents'] === 123_400 && $p['ready'] === true);
        $this->preview($user, $token, ['amount_locale' => 'eu'])->assertViewHas('preview', fn (array $p) => $p['rows'][0]['cents'] === null && $p['rows'][0]['error'] !== null);
    }

    public function test_a_bom_and_a_semicolon_delimiter_are_handled(): void
    {
        [$user, $account] = $this->fixture();
        $token = $this->upload($user, $account, "\xEF\xBB\xBFDate;Description;Amount\n2026-08-01;With bom;-5,00\n");

        $this->preview($user, $token)->assertViewHas('preview', fn (array $p) => $p['counts']['importable'] === 1 && $p['rows'][0]['description'] === 'With bom');
    }

    public function test_invalid_rows_are_listed_with_reasons_and_never_imported(): void
    {
        [$user, $account] = $this->fixture();
        $token = $this->upload($user, $account, "date,description,amount\n2026-08-01,Good row,-10.00\nnot a date,Bad date,-5.00\n2026-08-03,Bad amount,abc\n2026-08-04,,-1.00\n2026-08-05,Zero amount,0.00\n");

        $this->preview($user, $token)->assertViewHas('preview', fn (array $p) => $p['counts']['importable'] === 1 && $p['counts']['invalid'] === 4);

        $this->store($user, $token)->assertSessionHasNoErrors();
        $this->assertSame(1, Transaction::count());
    }

    public function test_a_file_without_the_needed_columns_is_not_importable(): void
    {
        [$user, $account] = $this->fixture();
        $token = $this->upload($user, $account, "when,what\n2026-08-01,thing\n");

        $this->preview($user, $token)->assertOk()->assertSee('Could not find')
            ->assertViewHas('preview', fn (array $p) => $p['ready'] === false && $p['counts']['importable'] === 0);
    }

    // ── store ──────────────────────────────────────────────────────────────

    public function test_store_imports_rows_with_exact_cents_types_and_the_chosen_account(): void
    {
        [$user, $account] = $this->fixture();
        $token = $this->upload($user, $account, "date,description,amount\n2026-08-01,Grocery Store,-45.50\n2026-08-02,Paycheck,\"1,500.00\"\n2026-08-03,Bracketed refund,(12.25)\n");

        $this->store($user, $token)->assertRedirect(route('transactions.index'));

        $rows = Transaction::orderBy('date')->get();
        $this->assertCount(3, $rows);
        $this->assertSame([4_550, 150_000, 1_225], $rows->pluck('amount_cents')->all());
        $this->assertSame(['expense', 'income', 'expense'], $rows->pluck('type')->all());
        $this->assertSame([$account->id], $rows->pluck('account_id')->unique()->values()->all());
        $this->assertSame('ZAR', $rows[0]->currency);
        $this->assertSame(100_000 - 4_550 + 150_000 - 1_225, Account::withBalance()->findOrFail($account->id)->balanceCents());
    }

    public function test_store_refuses_to_run_without_an_explicit_date_format_when_it_is_ambiguous(): void
    {
        [$user, $account] = $this->fixture();
        $token = $this->upload($user, $account, "date,description,amount\n03/04/2026,Ambiguous,-10.00\n");

        $this->actingAs($user)->post(route('transactions.import.store'), ['token' => $token, 'amount_locale' => 'en'])
            ->assertSessionHasErrors('date_format');

        $this->assertSame(0, Transaction::count());
    }

    public function test_store_honours_the_chosen_format(): void
    {
        [$user, $account] = $this->fixture();
        $token = $this->upload($user, $account, "date,description,amount\n03/04/2026,Ambiguous,-10.00\n");

        $this->store($user, $token, ['date_format' => 'mdy'])->assertSessionHasNoErrors();

        $this->assertSame('2026-03-04', Transaction::firstOrFail()->date->toDateString());
    }

    public function test_categories_match_by_name_but_only_when_their_type_fits_the_row(): void
    {
        [$user, $account] = $this->fixture();
        $food = Category::factory()->for($user)->create(['name' => 'Groceries']);
        $salary = Category::factory()->for($user)->income()->create(['name' => 'Salary']);
        $token = $this->upload($user, $account, "date,description,amount,category\n2026-08-01,Shop,-45.50,groceries\n2026-08-02,Pay,1500.00,SALARY\n2026-08-03,Odd refund,25.00,Groceries\n");

        $this->preview($user, $token)->assertViewHas('preview', fn (array $p) => $p['rows'][2]['category_id'] === null && $p['rows'][2]['category_note'] !== null);

        $this->store($user, $token)->assertSessionHasNoErrors();

        $byDesc = Transaction::get()->keyBy('description');
        $this->assertSame($food->id, $byDesc['Shop']->category_id);
        $this->assertSame($salary->id, $byDesc['Pay']->category_id);
        $this->assertNull($byDesc['Odd refund']->category_id, 'an expense category must never land on an income row');
    }

    public function test_a_default_category_fills_unmatched_rows_of_the_same_type_only(): void
    {
        [$user, $account] = $this->fixture();
        $misc = Category::factory()->for($user)->create(['name' => 'Miscellaneous']);
        $token = $this->upload($user, $account, "date,description,amount\n2026-08-01,Something,-10.00\n2026-08-02,Income thing,10.00\n");

        $this->store($user, $token, ['default_category_id' => $misc->id])->assertSessionHasNoErrors();

        $byDesc = Transaction::get()->keyBy('description');
        $this->assertSame($misc->id, $byDesc['Something']->category_id);
        $this->assertNull($byDesc['Income thing']->category_id);
    }

    public function test_rows_the_user_unticked_are_skipped(): void
    {
        [$user, $account] = $this->fixture();
        $token = $this->upload($user, $account, "date,description,amount\n2026-08-01,Keep me,-10.00\n2026-08-02,Skip me,-20.00\n");

        $this->store($user, $token, ['skip' => [3]])->assertSessionHasNoErrors();

        $this->assertSame(['Keep me'], Transaction::pluck('description')->all());
    }

    public function test_a_file_larger_than_php_max_input_vars_still_imports_because_only_a_token_is_posted(): void
    {
        [$user, $account] = $this->fixture();
        $lines = ['date,description,amount'];
        for ($i = 1; $i <= 1200; $i++) {
            $lines[] = sprintf('2026-08-%02d,Bulk row %d,-%d.%02d', ($i % 28) + 1, $i, $i, $i % 100);
        }
        $token = $this->upload($user, $account, implode("\n", $lines)."\n");

        $this->store($user, $token)->assertSessionHasNoErrors();

        $this->assertSame(1200, Transaction::count());
    }

    // ── duplicates ─────────────────────────────────────────────────────────

    public function test_importing_the_same_file_twice_skips_every_row_the_second_time(): void
    {
        [$user, $account] = $this->fixture();
        $contents = "date,description,amount\n2026-08-01,Grocery Store,-45.50\n2026-08-02,Paycheck,1500.00\n";

        $this->store($user, $this->upload($user, $account, $contents))->assertSessionHasNoErrors();
        $this->assertSame(2, Transaction::count());

        $second = $this->upload($user, $account, $contents);
        $this->preview($user, $second)->assertOk()
            ->assertSee('already imported')
            ->assertViewHas('preview', fn (array $p) => $p['counts']['duplicates'] === 2 && $p['counts']['importable'] === 0
                && $p['rows'][0]['duplicate'] === true);

        $this->store($user, $second)->assertSessionHasNoErrors();
        $this->assertSame(2, Transaction::count(), 're-importing must not double count');
        $this->assertSame(100_000 - 4_550 + 150_000, Account::withBalance()->findOrFail($account->id)->balanceCents());
    }

    public function test_identical_rows_in_one_file_are_both_imported_but_both_recognised_next_time(): void
    {
        [$user, $account] = $this->fixture();
        $contents = "date,description,amount\n2026-08-01,Coffee,-35.00\n2026-08-01,Coffee,-35.00\n";

        $this->store($user, $this->upload($user, $account, $contents))->assertSessionHasNoErrors();
        $this->assertSame(2, Transaction::count(), 'two real coffees are two transactions');

        $again = $this->upload($user, $account, $contents);
        $this->preview($user, $again)->assertViewHas('preview', fn (array $p) => $p['counts']['duplicates'] === 2);
        $this->store($user, $again);
        $this->assertSame(2, Transaction::count());
    }

    public function test_a_longer_statement_only_adds_the_new_rows(): void
    {
        [$user, $account] = $this->fixture();
        $this->store($user, $this->upload($user, $account, "date,description,amount\n2026-08-01,One,-10.00\n"));

        $this->store($user, $this->upload($user, $account, "date,description,amount\n2026-08-01,One,-10.00\n2026-08-02,Two,-20.00\n"));

        $this->assertSame(['One', 'Two'], Transaction::orderBy('date')->pluck('description')->all());
    }

    public function test_duplicate_detection_is_per_account(): void
    {
        [$user, $account] = $this->fixture();
        $other = Account::factory()->for($user)->create();
        $contents = "date,description,amount\n2026-08-01,Shared row,-10.00\n";

        $this->store($user, $this->upload($user, $account, $contents));
        $this->store($user, $this->upload($user, $other, $contents));

        $this->assertSame(2, Transaction::count());
    }

    public function test_duplicate_detection_ignores_case_and_spacing_in_the_description(): void
    {
        [$user, $account] = $this->fixture();
        $this->store($user, $this->upload($user, $account, "date,description,amount\n2026-08-01,WOOLWORTHS   Cape Town,-10.00\n"));

        $again = $this->upload($user, $account, "date,description,amount\n2026-08-01,woolworths cape town ,-10.00\n");

        $this->preview($user, $again)->assertViewHas('preview', fn (array $p) => $p['counts']['duplicates'] === 1);
    }

    public function test_the_fingerprint_is_stored_and_backed_by_a_unique_index(): void
    {
        [$user, $account] = $this->fixture();
        $this->store($user, $this->upload($user, $account, "date,description,amount\n2026-08-01,Indexed,-10.00\n"));

        $tx = Transaction::firstOrFail();
        $expected = ImportFingerprint::make($account->id, '2026-08-01', -1_000, 'Indexed', 1);
        $this->assertSame($expected, $tx->import_fingerprint);
        $this->assertNotNull($tx->import_batch_id);

        $this->expectException(QueryException::class);
        DB::table('transactions')->insert([
            'account_id' => $account->id, 'user_id' => $user->id, 'description' => 'Forced duplicate', 'amount' => '10.00',
            'amount_cents' => 1_000, 'currency' => 'ZAR', 'type' => 'expense', 'date' => '2026-08-01',
            'import_fingerprint' => $expected, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_deleting_an_imported_transaction_lets_the_same_row_be_imported_again(): void
    {
        [$user, $account] = $this->fixture();
        $contents = "date,description,amount\n2026-08-01,Imported by mistake,-10.00\n";
        $this->store($user, $this->upload($user, $account, $contents));
        Transaction::firstOrFail()->delete();

        $this->store($user, $this->upload($user, $account, $contents));

        $this->assertSame(1, Transaction::count());
        $this->assertSame(2, Transaction::withTrashed()->count());
    }

    public function test_fingerprints_are_stable_and_distinguish_what_matters(): void
    {
        $base = ImportFingerprint::make(1, '2026-08-01', -1_000, 'Coffee', 1);

        $this->assertSame($base, ImportFingerprint::make(1, '2026-08-01', -1_000, '  COFFEE ', 1));
        $this->assertNotSame($base, ImportFingerprint::make(2, '2026-08-01', -1_000, 'Coffee', 1));
        $this->assertNotSame($base, ImportFingerprint::make(1, '2026-08-02', -1_000, 'Coffee', 1));
        $this->assertNotSame($base, ImportFingerprint::make(1, '2026-08-01', 1_000, 'Coffee', 1), 'income and expense differ');
        $this->assertNotSame($base, ImportFingerprint::make(1, '2026-08-01', -1_001, 'Coffee', 1));
        $this->assertNotSame($base, ImportFingerprint::make(1, '2026-08-01', -1_000, 'Coffee', 2));
        $this->assertSame(64, strlen($base));
    }

    // ── atomicity and audit ────────────────────────────────────────────────

    public function test_the_import_is_all_or_nothing(): void
    {
        [$user, $account] = $this->fixture();
        $token = $this->upload($user, $account, "date,description,amount\n2026-08-01,First,-10.00\n2026-08-02,Second,-20.00\n2026-08-03,Third,-30.00\n");

        $armed = true;
        $count = 0;
        Transaction::creating(function (Transaction $tx) use (&$armed, &$count) {
            if ($armed && ++$count === 2) {
                throw new \RuntimeException('simulated failure on the second row');
            }
        });

        try {
            $this->withoutExceptionHandling();
            $this->store($user, $token);
            $this->fail('the simulated failure should have propagated');
        } catch (\RuntimeException) {
            // expected
        } finally {
            $armed = false;
        }

        $this->assertSame(0, Transaction::count(), 'a failure on row 2 must roll back row 1');
        $this->assertSame(0, FinanceAuditLog::where('action', 'imported')->count());
        Storage::disk('local')->assertExists("finance-imports/{$user->id}/{$token}.csv");
    }

    public function test_the_whole_file_result_is_one_audit_row_and_rows_are_not_logged_individually(): void
    {
        [$user, $account] = $this->fixture();
        $token = $this->upload($user, $account, "date,description,amount\n2026-08-01,Row one,-10.00\n2026-08-02,Row two,20.00\nbad,Bad row,1.00\n", 'july-statement.csv');
        $this->store($user, $token);

        $row = FinanceAuditLog::where('action', 'imported')->sole();
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame('import', $row->subject_type);
        $this->assertSame('july-statement.csv', $row->changes['file']);
        $this->assertSame($account->id, $row->changes['account_id']);
        $this->assertSame('ymd', $row->changes['date_format']);
        $this->assertSame('en', $row->changes['amount_locale']);
        $this->assertSame(3, $row->changes['rows_total']);
        $this->assertSame(2, $row->changes['imported']);
        $this->assertSame(0, $row->changes['skipped_duplicates']);
        $this->assertSame(1, $row->changes['skipped_invalid']);
        $this->assertSame(2_000, $row->changes['income_cents']);
        $this->assertSame(1_000, $row->changes['expense_cents']);
        $this->assertSame('ZAR', $row->changes['currency']);
        $this->assertStringStartsWith('import:', (string) $row->context);

        $this->assertSame(0, FinanceAuditLog::where('subject_type', 'transaction')->where('action', 'created')->count());
        $this->assertSame(Transaction::pluck('import_batch_id')->unique()->count(), 1);
    }

    public function test_the_audit_row_counts_duplicates_on_a_re_import(): void
    {
        [$user, $account] = $this->fixture();
        $contents = "date,description,amount\n2026-08-01,Row one,-10.00\n";
        $this->store($user, $this->upload($user, $account, $contents));
        $this->store($user, $this->upload($user, $account, $contents));

        $second = FinanceAuditLog::where('action', 'imported')->orderByDesc('id')->first();
        $this->assertSame(0, $second->changes['imported']);
        $this->assertSame(1, $second->changes['skipped_duplicates']);
    }

    public function test_the_staged_file_is_removed_after_a_successful_import(): void
    {
        [$user, $account] = $this->fixture();
        $token = $this->upload($user, $account, "date,description,amount\n2026-08-01,Row,-10.00\n");
        $this->store($user, $token);

        Storage::disk('local')->assertMissing("finance-imports/{$user->id}/{$token}.csv");
        $this->preview($user, $token)->assertRedirect(route('transactions.import.create'));
    }

    public function test_abandoned_staged_files_are_pruned(): void
    {
        [$user, $account] = $this->fixture();
        $old = $this->upload($user, $account, "date,description,amount\n2026-08-01,Old,-10.00\n");
        $fresh = $this->upload($user, $account, "date,description,amount\n2026-08-01,Fresh,-10.00\n");
        touch(Storage::disk('local')->path("finance-imports/{$user->id}/{$old}.csv"), time() - 3 * 86400);

        $this->artisan('finance:prune-imports')->assertSuccessful();

        Storage::disk('local')->assertMissing("finance-imports/{$user->id}/{$old}.csv");
        Storage::disk('local')->assertExists("finance-imports/{$user->id}/{$fresh}.csv");
    }

    // ── ownership ──────────────────────────────────────────────────────────

    public function test_a_user_cannot_preview_or_store_another_users_staged_file(): void
    {
        [$owner, $account] = $this->fixture();
        $attacker = User::factory()->withPersonalTeam()->create();
        $token = $this->upload($owner, $account, "date,description,amount\n2026-08-01,Private row,-10.00\n");

        $this->preview($attacker, $token)->assertRedirect(route('transactions.import.create'));
        $this->store($attacker, $token)->assertSessionHasErrors('token');

        $this->assertSame(0, Transaction::withoutGlobalScopes()->count());
    }

    public function test_a_user_cannot_import_into_another_users_account(): void
    {
        [$user] = $this->fixture();
        $other = User::factory()->withPersonalTeam()->create();
        $theirs = Account::factory()->for($other)->create();

        $this->actingAs($user)->post(route('transactions.import.upload'), [
            'account_id' => $theirs->id, 'file' => $this->csv("date,description,amount\n2026-08-01,Sneaky,-10.00\n"),
        ])->assertSessionHasErrors('account_id');
    }

    public function test_the_upload_must_be_a_csv(): void
    {
        [$user, $account] = $this->fixture();

        $this->actingAs($user)->post(route('transactions.import.upload'), [
            'account_id' => $account->id, 'file' => UploadedFile::fake()->create('photo.png', 10, 'image/png'),
        ])->assertSessionHasErrors('file');
    }

    public function test_a_default_category_must_belong_to_the_user(): void
    {
        [$user, $account] = $this->fixture();
        $theirs = Category::factory()->for(User::factory()->create())->create();
        $token = $this->upload($user, $account, "date,description,amount\n2026-08-01,Row,-10.00\n");

        $this->store($user, $token, ['default_category_id' => $theirs->id])->assertSessionHasErrors('default_category_id');
        $this->assertSame(0, Transaction::count());
    }
}
