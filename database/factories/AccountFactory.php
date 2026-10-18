<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'team_id' => null,
            'name' => 'Account '.fake()->unique()->bothify('??-####'),
            'type' => 'checking',
            'currency' => 'ZAR',
            'institution' => null,
            'opening_balance_cents' => 0,
            // Null means "no lower bound": every transaction counts. Tests that
            // care about the cut-off set it explicitly.
            'opening_balance_date' => null,
        ];
    }

    /** An account that belongs to a company's ledger (no personal owner). */
    public function forCompany(Team|int $team): static
    {
        return $this->state(['user_id' => null, 'team_id' => $team instanceof Team ? $team->id : $team]);
    }

    public function currency(string $currency): static
    {
        return $this->state(['currency' => $currency]);
    }

    public function opening(int $cents, ?string $date = null): static
    {
        return $this->state(['opening_balance_cents' => $cents, 'opening_balance_date' => $date]);
    }

    public function ofType(string $type): static
    {
        return $this->state(['type' => $type]);
    }
}
