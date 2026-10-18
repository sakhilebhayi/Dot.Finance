<?php

namespace Tests\Concerns;

use App\Models\Account;
use App\Models\Category;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Ledger\LedgerContextManager;
use Illuminate\Support\Str;

/**
 * Fixtures for the personal-versus-company tests: companies as InfoDot makes them
 * (a non-personal team with an InfoDot uuid), members at each role, and a way to
 * sign in with a company (or the personal ledger) as the active context.
 */
trait BuildsCompanyLedgers
{
    protected function user(): User
    {
        return User::factory()->withPersonalTeam()->create();
    }

    /** A company as the InfoDot sign-in creates it. */
    protected function company(User $owner, string $name = 'Acme (Pty) Ltd'): Team
    {
        return Team::forceCreate([
            'user_id' => $owner->id,
            'name' => $name,
            'personal_team' => false,
            'infodot_team_uuid' => (string) Str::uuid(),
        ]);
    }

    /** A new user who belongs to $company at $role (admin | editor | viewer, or anything else for an unknown role). */
    protected function memberOf(Team $company, string $role): User
    {
        $member = $this->user();
        $company->users()->attach($member->id, ['role' => $role]);

        return $member->fresh();
    }

    /** Sign in with $company as the active ledger. */
    protected function inCompany(User $user, Team $company): static
    {
        return $this->actingAs($user->fresh())->withSession([LedgerContextManager::SESSION_KEY => $company->id]);
    }

    /** Sign in with the personal ledger active. */
    protected function inPersonal(User $user): static
    {
        return $this->actingAs($user->fresh())->withSession([LedgerContextManager::SESSION_KEY => 'personal']);
    }

    protected function companyAccount(Team $company, array $attributes = []): Account
    {
        return Account::factory()->forCompany($company)->create($attributes);
    }

    protected function companyCategory(Team $company, array $attributes = []): Category
    {
        return Category::factory()->forCompany($company)->create($attributes);
    }

    protected function entryOn(Account $account, array $attributes = []): Transaction
    {
        return Transaction::factory()->create(array_merge(['account_id' => $account->id], $attributes));
    }
}
