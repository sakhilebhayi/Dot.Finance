<?php

namespace Tests\Feature\Company;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\FinanceAuditLog;
use App\Models\Insight;
use App\Models\RecurringRule;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Ledger\LedgerContext;
use App\Support\Ledger\LedgerContextManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\TestCase;

/**
 * The one scope every ledger model shares: personal context sees the user's own
 * rows with no company, company context sees that company's rows for a member,
 * and anything that cannot establish a context sees nothing at all.
 */
class LedgerContextScopeTest extends TestCase
{
    use BuildsCompanyLedgers;
    use RefreshDatabase;

    private function scopedModels(): array
    {
        return [Account::class, Category::class, Budget::class, Transaction::class, RecurringRule::class, FinanceAuditLog::class, Insight::class];
    }

    /** One row of every ledger model in the personal ledger of $user and in $company. */
    private function seedBoth(User $user, Team $company): void
    {
        $personal = Account::factory()->create(['user_id' => $user->id]);
        $this->entryOn($personal);
        Budget::factory()->create(['user_id' => $user->id]);
        RecurringRule::factory()->create(['user_id' => $user->id]);
        Insight::create(['user_id' => $user->id, 'insight' => 'personal', 'type' => 'x', 'iso_week' => '2026-W01']);

        $shared = $this->companyAccount($company);
        $this->entryOn($shared);
        Budget::factory()->forCompany($company)->create();
        RecurringRule::factory()->forCompany($company)->create();
        Insight::create(['team_id' => $company->id, 'insight' => 'company', 'type' => 'x', 'iso_week' => '2026-W01']);
    }

    public function test_personal_context_sees_only_the_users_own_personal_rows(): void
    {
        $user = $this->user();
        $company = $this->company($user);
        $this->seedBoth($user, $company);
        $stranger = $this->user();
        Account::factory()->create(['user_id' => $stranger->id]);

        $this->inPersonal($user);

        foreach ($this->scopedModels() as $model) {
            $ids = $model::query()->get();
            $this->assertNotEmpty($ids, class_basename($model).' should have personal rows');
            $this->assertTrue($ids->every(fn ($row) => $row->user_id === $user->id && $row->team_id === null), class_basename($model).' leaked a row outside the personal ledger');
        }
    }

    public function test_company_context_sees_only_that_companys_rows_for_a_member(): void
    {
        $user = $this->user();
        $company = $this->company($user);
        $other = $this->company($this->user(), 'Globex');
        $this->seedBoth($user, $company);
        $this->companyAccount($other);

        $this->inCompany($user, $company);

        foreach ($this->scopedModels() as $model) {
            $rows = $model::query()->get();
            $this->assertNotEmpty($rows, class_basename($model).' should have company rows');
            $this->assertTrue($rows->every(fn ($row) => $row->team_id === $company->id && $row->user_id === null), class_basename($model).' leaked a row outside the company');
        }
    }

    public function test_every_scoped_model_fails_closed_when_nobody_is_signed_in(): void
    {
        $user = $this->user();
        $this->seedBoth($user, $this->company($user));
        Auth::logout();

        foreach ($this->scopedModels() as $model) {
            $this->assertSame(0, $model::query()->count(), class_basename($model).' must not return rows without a context');
        }
    }

    public function test_a_signed_in_user_whose_session_names_a_company_they_are_not_in_sees_nothing(): void
    {
        $owner = $this->user();
        $company = $this->company($owner);
        $this->seedBoth($owner, $company);
        $outsider = $this->user();

        $this->inCompany($outsider, $company);

        foreach ($this->scopedModels() as $model) {
            $this->assertSame(0, $model::query()->count(), class_basename($model).' must fail closed for a non-member');
        }
    }

    public function test_a_member_who_is_removed_loses_the_company_immediately(): void
    {
        $owner = $this->user();
        $company = $this->company($owner);
        $this->companyAccount($company);
        $member = $this->memberOf($company, 'admin');

        $this->inCompany($member, $company);
        $this->assertSame(1, Account::count());

        $company->users()->detach($member->id);
        $this->actingAs($member->fresh());

        $this->assertSame(0, Account::count());
    }

    public function test_a_personal_team_or_a_team_with_no_infodot_link_is_never_a_company_context(): void
    {
        $user = $this->user();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $this->assertNotNull($account);

        $personalTeam = $user->ownedTeams()->where('personal_team', true)->firstOrFail();
        $unlinked = Team::forceCreate(['user_id' => $user->id, 'name' => 'Hand made', 'personal_team' => false]);

        foreach ([$personalTeam, $unlinked] as $team) {
            $this->actingAs($user->fresh())->withSession([LedgerContextManager::SESSION_KEY => $team->id]);
            $this->assertNull(LedgerContext::current(), "{$team->name} must not be a ledger context");
            $this->assertSame(0, Account::count());
        }
    }

    public function test_a_company_context_cannot_be_built_for_a_personal_or_unlinked_team(): void
    {
        $user = $this->user();
        $personalTeam = $user->ownedTeams()->where('personal_team', true)->firstOrFail();
        $unlinked = Team::forceCreate(['user_id' => $user->id, 'name' => 'Hand made', 'personal_team' => false]);

        foreach ([$personalTeam, $unlinked] as $team) {
            try {
                LedgerContext::company($team);
                $this->fail('A company context was built for '.$team->name);
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('company', $e->getMessage());
            }
        }
    }

    public function test_the_default_context_is_personal(): void
    {
        $user = $this->user();
        $this->actingAs($user);

        $this->assertTrue(LedgerContext::current()->isPersonal());
        $this->assertSame($user->id, LedgerContext::current()->userId);
    }

    public function test_the_explicit_helper_is_how_console_and_queue_code_names_a_context(): void
    {
        $user = $this->user();
        $company = $this->company($user);
        $this->seedBoth($user, $company);
        Auth::logout();

        // seedBoth() makes two accounts in each ledger (one directly, one through the recurring rule's factory).
        $this->assertSame(0, Account::count());
        $this->assertSame(2, LedgerContext::run(LedgerContext::personal($user), fn () => Account::count()));
        $this->assertSame(2, LedgerContext::run(LedgerContext::company($company), fn () => Account::count()));
        $this->assertSame(0, Account::count(), 'the helper must not be sticky');
    }

    public function test_the_explicit_helper_is_released_even_when_the_callback_throws(): void
    {
        $user = $this->user();
        Account::factory()->create(['user_id' => $user->id]);
        Auth::logout();

        try {
            LedgerContext::run(LedgerContext::personal($user), function () {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, Account::count());
    }

    public function test_contexts_nest_and_the_inner_one_wins(): void
    {
        $user = $this->user();
        $company = $this->company($user);
        $this->seedBoth($user, $company);
        Auth::logout();

        $result = LedgerContext::run(LedgerContext::personal($user), function () use ($company) {
            $outer = Account::query()->value('team_id');
            $inner = LedgerContext::run(LedgerContext::company($company), fn () => Account::query()->value('team_id'));

            return [$outer, $inner, Account::query()->value('team_id')];
        });

        $this->assertSame([null, $company->id, null], $result);
    }

    public function test_a_company_context_is_listed_for_every_company_the_user_belongs_to_and_nothing_else(): void
    {
        $user = $this->user();
        $owned = $this->company($user, 'Owned Ltd');
        $joined = $this->company($this->user(), 'Joined Ltd');
        $joined->users()->attach($user->id, ['role' => 'viewer']);
        $this->company($this->user(), 'Stranger Ltd');
        Team::forceCreate(['user_id' => $user->id, 'name' => 'Hand made', 'personal_team' => false]);

        $keys = collect(app(LedgerContextManager::class)->contextsFor($user->fresh()))->map->key()->all();

        $this->assertEqualsCanonicalizing(['personal:'.$user->id, 'company:'.$owned->id, 'company:'.$joined->id], $keys);
    }

    public function test_a_row_is_found_by_its_id_only_inside_its_own_context(): void
    {
        $user = $this->user();
        $company = $this->company($user);
        $personal = Account::factory()->create(['user_id' => $user->id]);
        $shared = $this->companyAccount($company);

        $this->inPersonal($user);
        $this->assertNotNull(Account::find($personal->id));
        $this->assertNull(Account::find($shared->id));

        $this->inCompany($user, $company);
        $this->assertNull(Account::find($personal->id));
        $this->assertNotNull(Account::find($shared->id));
    }

    public function test_the_unknown_uuid_is_not_a_company(): void
    {
        $user = $this->user();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Linked', 'personal_team' => false, 'infodot_team_uuid' => (string) Str::uuid()]);

        $this->assertTrue(LedgerContext::company($team)->isCompany());
    }
}
