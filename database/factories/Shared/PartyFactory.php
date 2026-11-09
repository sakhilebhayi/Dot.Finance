<?php

namespace Database\Factories\Shared;

use App\Models\Shared\Party;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Party>
 */
class PartyFactory extends Factory
{
    protected $model = Party::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'team_id' => null,
            'type' => 'customer',
            'name' => 'Party '.fake()->unique()->bothify('??-####'),
            'status' => 'active',
        ];
    }

    /** A party in a company's ledger (no personal owner). */
    public function forCompany(Team|int $team): static
    {
        return $this->state(['user_id' => null, 'team_id' => $team instanceof Team ? $team->id : $team]);
    }

    public function supplier(): static
    {
        return $this->state(['type' => 'supplier']);
    }

    public function both(): static
    {
        return $this->state(['type' => 'both']);
    }
}
