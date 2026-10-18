<?php

namespace Database\Factories;

use App\Models\Category;
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
            'name' => 'Category '.fake()->unique()->bothify('??-####'),
            'icon' => null,
            'color' => '#6366f1',
            'type' => 'expense',
        ];
    }

    public function income(): static
    {
        return $this->state(['type' => 'income']);
    }
}
