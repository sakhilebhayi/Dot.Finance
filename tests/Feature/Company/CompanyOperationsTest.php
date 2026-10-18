<?php

namespace Tests\Feature\Company;

use App\Actions\Jetstream\DeleteTeam;
use App\Actions\Jetstream\DeleteUser;
use App\Models\Account;
use App\Models\Insight;
use App\Models\RecurringRule;
use App\Models\Team;
use App\Models\Transaction;
use App\Services\LedgerReconciler;
use App\Services\TransactionRecurrenceService;
use App\Support\Ledger\LedgerContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Http\Livewire\DeleteTeamForm;
use Laravel\Jetstream\Http\Livewire\DeleteUserForm;
use Livewire\Livewire;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\TestCase;

/**
 * Everything that has no signed-in person: the scheduler, the weekly job, the balance check,
 * team and user deletion, and the imports that must not mix ledgers.
 */
class CompanyOperationsTest extends TestCase
{
    use BuildsCompanyLedgers;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function runway(Account $account, int $spendCents = 40_000): void
    {
        $this->entryOn($account, ['type' => 'expense', 'amount_cents' => $spendCents, 'date' => now()->subDays(3)->toDateString()]);
    }

    // ── weekly summaries ───────────────────────────────────────────────────

    public function test_the_weekly_job_writes_one_summary_per_ledger_per_week_and_keeps_them_apart(): void
    {
        Carbon::setTestNow('2026-09-15');
        $user = $this->user();
        $company = $this->company($this->user());
        $this->runway(Account::factory()->create(['user_id' => $user->id, 'opening_balance_cents' => 100_000]));
        $this->runway($this->companyAccount($company, ['opening_balance_cents' => 100_000]));

        $this->artisan('finance:generate-insights')->assertExitCode(0);
        $this->artisan('finance:generate-insights')->assertExitCode(0);

        $this->assertSame(1, Insight::withoutGlobalScopes()->where('user_id', $user->id)->count(), 'one personal summary');
        $this->assertSame(1, Insight::withoutGlobalScopes()->where('team_id', $company->id)->count(), 'one company summary');
        $this->assertSame(['2026-W38', '2026-W38'], Insight::withoutGlobalScopes()->orderBy('id')->pluck('iso_week')->all(), 'the same ISO week in two ledgers is fine');
    }

    public function test_a_company_summary_is_about_the_company_only(): void
    {
        Carbon::setTestNow('2026-09-15');
        $user = $this->user();
        $company = $this->company($this->user());
        // a healthy personal ledger and a company that spends more than it holds
        $this->entryOn(Account::factory()->create(['user_id' => $user->id, 'opening_balance_cents' => 90_000_000]), ['type' => 'income', 'amount_cents' => 1_000, 'date' => now()->toDateString()]);
        $this->runway($this->companyAccount($company, ['opening_balance_cents' => 50_000]), 40_000);

        $this->artisan('finance:generate-insights');

        $this->assertSame('reserve_runway_healthy', Insight::withoutGlobalScopes()->where('user_id', $user->id)->value('type'));
        $this->assertSame('reserve_runway_warning', Insight::withoutGlobalScopes()->where('team_id', $company->id)->value('type'));
    }

    public function test_the_company_summary_page_shows_the_company_and_the_personal_page_does_not(): void
    {
        Carbon::setTestNow('2026-09-15');
        $owner = $this->user();
        $company = $this->company($owner);
        $this->runway($this->companyAccount($company, ['opening_balance_cents' => 50_000]));
        $this->artisan('finance:generate-insights');

        $this->inCompany($owner, $company)->get(route('insights.index'))->assertOk()->assertSee('run out');
        $this->inPersonal($owner)->get(route('insights.index'))->assertOk()->assertDontSee('run out');
    }

    public function test_a_team_that_stopped_being_a_company_is_skipped_by_the_weekly_job_instead_of_crashing_it(): void
    {
        Carbon::setTestNow('2026-09-15');
        $company = $this->company($this->user());
        $this->runway($this->companyAccount($company, ['opening_balance_cents' => 50_000]));
        $company->forceFill(['infodot_team_uuid' => null])->save();

        $this->artisan('finance:generate-insights')->assertExitCode(0);

        $this->assertSame(0, Insight::withoutGlobalScopes()->count());
    }

    // ── scheduler ─────────────────────────────────────────────────────────

    public function test_the_scheduler_skips_a_rule_whose_account_was_deleted_in_a_company_too(): void
    {
        Carbon::setTestNow('2026-09-15');
        $company = $this->company($this->user());
        $rule = RecurringRule::factory()->forCompany($company)->create(['next_occurrence_date' => '2026-09-01', 'start_date' => '2026-09-01']);
        Account::withoutGlobalScopes()->whereKey($rule->account_id)->firstOrFail()->delete();

        $this->assertSame(0, app(TransactionRecurrenceService::class)->generateDueOccurrences());
    }

    public function test_the_scheduler_leaves_no_ambient_context_behind(): void
    {
        Carbon::setTestNow('2026-09-15');
        $company = $this->company($this->user());
        RecurringRule::factory()->forCompany($company)->create(['next_occurrence_date' => '2026-09-01', 'start_date' => '2026-09-01']);

        app(TransactionRecurrenceService::class)->generateDueOccurrences();

        $this->assertNull(LedgerContext::current());
        $this->assertSame(0, Transaction::count());
    }

    // ── balance check ─────────────────────────────────────────────────────

    public function test_the_balance_check_reports_every_ledger_on_its_own(): void
    {
        $user = $this->user();
        $company = $this->company($this->user(), 'Acme Mining');
        Account::factory()->create(['user_id' => $user->id, 'name' => 'My cheque']);
        $this->companyAccount($company, ['name' => 'Acme operating']);

        $this->assertSame(0, Artisan::call('finance:check-balances'));
        $output = Artisan::output();

        foreach (['personal:'.$user->id, 'company:'.$company->id, 'My cheque', 'Acme operating'] as $expected) {
            $this->assertStringContainsString($expected, $output);
        }
    }

    public function test_the_balance_check_can_be_limited_to_one_person_or_one_company(): void
    {
        $user = $this->user();
        $company = $this->company($this->user());
        Account::factory()->create(['user_id' => $user->id, 'name' => 'My cheque']);
        $this->companyAccount($company, ['name' => 'Acme operating']);

        Artisan::call('finance:check-balances', ['--team' => $company->id]);
        $this->assertStringContainsString('Acme operating', Artisan::output());
        $this->assertStringNotContainsString('My cheque', Artisan::output());

        Artisan::call('finance:check-balances', ['--user' => $user->id]);
        $this->assertStringContainsString('My cheque', Artisan::output());
        $this->assertStringNotContainsString('Acme operating', Artisan::output());
    }

    public function test_the_balance_check_refuses_two_targets_or_a_team_that_is_not_a_company(): void
    {
        $user = $this->user();
        $personalTeam = $user->ownedTeams()->where('personal_team', true)->firstOrFail();

        $this->artisan('finance:check-balances', ['--user' => $user->id, '--team' => 1])->expectsOutputToContain('Pick one')->assertExitCode(1);
        $this->artisan('finance:check-balances', ['--team' => $personalTeam->id])->expectsOutputToContain('not a company')->assertExitCode(1);
        $this->artisan('finance:check-balances', ['--team' => 99999])->expectsOutputToContain('not a company')->assertExitCode(1);
    }

    public function test_the_reconciler_flags_a_row_that_points_across_ledgers(): void
    {
        $user = $this->user();
        $company = $this->company($user);
        $personal = Account::factory()->create(['user_id' => $user->id]);
        $shared = $this->companyAccount($company);

        // Written around the models (raw SQL): each row passes the owner rule on its own, but the pair crosses ledgers.
        $now = now();
        DB::table('transactions')->insert(['account_id' => $personal->id, 'team_id' => $company->id, 'description' => 'smuggled', 'amount' => '1.00', 'amount_cents' => 100, 'currency' => 'ZAR', 'type' => 'expense', 'date' => '2026-01-01', 'created_at' => $now, 'updated_at' => $now]);

        $codes = collect((new LedgerReconciler)->run(LedgerContext::company($company))['findings'])->pluck('code')->all();
        $this->assertContains('context_mismatch', $codes);

        $clean = collect((new LedgerReconciler)->run(LedgerContext::personal($user))['findings'])->pluck('code')->all();
        $this->assertNotContains('context_mismatch', $clean, 'the personal ledger itself holds no crossing row');
        $this->assertNotNull($shared);
    }

    // ── import ────────────────────────────────────────────────────────────

    public function test_the_same_statement_imports_once_into_each_ledger(): void
    {
        Storage::fake('local');
        $owner = $this->user();
        $company = $this->company($owner);
        $mine = Account::factory()->create(['user_id' => $owner->id]);
        $shared = $this->companyAccount($company);
        $csv = "date,description,amount\n2026-08-01,Supplier invoice,-45.50\n";

        foreach ([[fn () => $this->inPersonal($owner), $mine], [fn () => $this->inCompany($owner, $company), $shared]] as [$enter, $account]) {
            $enter();
            $token = basename(parse_url($this->post(route('transactions.import.upload'), ['account_id' => $account->id, 'file' => UploadedFile::fake()->createWithContent('s.csv', $csv)])->headers->get('Location'), PHP_URL_PATH));
            $this->post(route('transactions.import.store'), ['token' => $token, 'date_format' => 'ymd', 'amount_locale' => 'en'])->assertRedirect(route('transactions.index'));
        }

        $this->assertSame(1, Transaction::withoutGlobalScopes()->where('user_id', $owner->id)->count());
        $this->assertSame(1, Transaction::withoutGlobalScopes()->where('team_id', $company->id)->count());
    }

    public function test_the_database_refuses_the_same_import_fingerprint_twice_inside_a_company(): void
    {
        $company = $this->company($this->user());
        $account = $this->companyAccount($company);
        $row = ['account_id' => $account->id, 'team_id' => $company->id, 'description' => 'x', 'amount' => '1.00', 'amount_cents' => 100, 'currency' => 'ZAR', 'type' => 'expense', 'date' => '2026-01-01', 'import_fingerprint' => str_repeat('c', 64), 'created_at' => now(), 'updated_at' => now()];
        DB::table('transactions')->insert($row);

        $this->expectException(QueryException::class);
        DB::table('transactions')->insert($row);
    }

    // ── deleting teams and people ──────────────────────────────────────────

    public function test_a_company_with_a_ledger_cannot_be_deleted(): void
    {
        $owner = $this->user();
        $company = $this->company($owner, 'Acme Mining');
        $this->companyAccount($company);

        try {
            app(DeleteTeam::class)->delete($company);
            $this->fail('deleting the team would have deleted the books');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Acme Mining', $e->errors()['team'][0]);
        }

        $this->assertNotNull(Team::find($company->id));
        $this->assertSame(1, Account::withoutGlobalScopes()->where('team_id', $company->id)->count());
    }

    public function test_the_delete_team_screen_shows_why_a_company_with_a_ledger_stays(): void
    {
        $owner = $this->user();
        $company = $this->company($owner, 'Acme Mining');
        $this->companyAccount($company);
        $this->actingAs($owner);

        Livewire::test(DeleteTeamForm::class, ['team' => $company->fresh()])
            ->call('deleteTeam')
            ->assertHasErrors(['team'])
            ->assertSee('Acme Mining has a company ledger');

        $this->assertNotNull(Team::find($company->id));
    }

    public function test_the_delete_account_screen_shows_why_an_owner_of_a_company_ledger_stays(): void
    {
        $owner = $this->user();
        $company = $this->company($owner, 'Acme Mining');
        $this->companyAccount($company);
        $this->actingAs($owner);

        Livewire::test(DeleteUserForm::class)
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasErrors(['password']);

        $this->assertNotNull($owner->fresh());
    }

    public function test_a_company_with_no_ledger_can_still_be_deleted(): void
    {
        $company = $this->company($this->user());

        app(DeleteTeam::class)->delete($company);

        $this->assertNull(Team::find($company->id));
    }

    public function test_a_person_who_owns_a_company_with_a_ledger_cannot_delete_their_account(): void
    {
        $owner = $this->user();
        $company = $this->company($owner);
        $this->companyAccount($company);

        try {
            app(DeleteUser::class)->delete($owner);
            $this->fail('deleting the person would have deleted the company books');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertNotNull($owner->fresh());
        $this->assertSame(1, Account::withoutGlobalScopes()->where('team_id', $company->id)->count());
    }

    public function test_deleting_a_person_still_removes_their_personal_ledger_as_before(): void
    {
        $user = $this->user();
        Account::factory()->create(['user_id' => $user->id]);

        app(DeleteUser::class)->delete($user);

        $this->assertSame(0, Account::withoutGlobalScopes()->count());
    }

    public function test_a_member_leaving_never_touches_the_companys_books(): void
    {
        $company = $this->company($this->user());
        $this->companyAccount($company);
        $member = $this->memberOf($company, 'admin');

        app(DeleteUser::class)->delete($member);

        $this->assertSame(1, Account::withoutGlobalScopes()->where('team_id', $company->id)->count());
    }
}
