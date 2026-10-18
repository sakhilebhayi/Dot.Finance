<?php

namespace Database\Factories;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Budget>
 */
class BudgetFactory extends Factory
{
    protected $model = Budget::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'team_id' => null,
            'category_id' => fn (array $attributes) => Category::factory()->create([
                'user_id' => $attributes['user_id'],
                'team_id' => $attributes['team_id'],
            ])->id,
            'amount_cents' => 100000,
            'currency' => 'ZAR',
            'month' => (int) now()->month,
            'year' => (int) now()->year,
        ];
    }

    /** A budget that belongs to a company's ledger (no personal owner). */
    public function forCompany(Team|int $team): static
    {
        return $this->state(['user_id' => null, 'team_id' => $team instanceof Team ? $team->id : $team]);
    }
}
