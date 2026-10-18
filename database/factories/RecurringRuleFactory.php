<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\RecurringRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringRule>
 */
class RecurringRuleFactory extends Factory
{
    protected $model = RecurringRule::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'account_id' => fn (array $attributes) => Account::factory()->create(['user_id' => $attributes['user_id']])->id,
            'description' => 'Rule '.fake()->unique()->bothify('??-####'),
            'amount_cents' => 120_000,
            'type' => 'expense',
            'recurrence_type' => 'monthly',
            'recurrence_interval' => 1,
            'start_date' => '2026-08-01',
            'next_occurrence_date' => '2026-09-01',
        ];
    }
}
