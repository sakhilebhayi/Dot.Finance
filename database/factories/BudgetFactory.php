<?php

namespace Database\Factories;

use App\Models\Budget;
use App\Models\Category;
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
            'category_id' => fn (array $attributes) => Category::factory()->create(['user_id' => $attributes['user_id']])->id,
            'amount_cents' => 100000,
            'currency' => 'ZAR',
            'month' => (int) now()->month,
            'year' => (int) now()->year,
        ];
    }
}
