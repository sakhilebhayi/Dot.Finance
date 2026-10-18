<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'team_id' => null,
            'name' => 'Category '.fake()->unique()->bothify('??-####'),
            'icon' => null,
            'color' => '#6366f1',
            'type' => 'expense',
        ];
    }

    /** A category that belongs to a company's ledger (no personal owner). */
    public function forCompany(Team|int $team): static
    {
        return $this->state(['user_id' => null, 'team_id' => $team instanceof Team ? $team->id : $team]);
    }

    public function income(): static
    {
        return $this->state(['type' => 'income']);
    }
}
