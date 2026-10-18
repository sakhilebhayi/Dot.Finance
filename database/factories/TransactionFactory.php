<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            // A transaction belongs to whichever ledger its account belongs to.
            'user_id' => fn (array $attributes) => Account::withoutGlobalScopes()->find($attributes['account_id'])->user_id,
            'team_id' => fn (array $attributes) => Account::withoutGlobalScopes()->find($attributes['account_id'])->team_id,
            'description' => 'Entry '.fake()->unique()->bothify('??-####'),
            'amount_cents' => 1000,
            'type' => 'expense',
            'date' => now()->toDateString(),
        ];
    }

    public function cents(int $cents): static
    {
        return $this->state(['amount_cents' => $cents]);
    }

    public function income(): static
    {
        return $this->state(['type' => 'income']);
    }

    public function expense(): static
    {
        return $this->state(['type' => 'expense']);
    }

    public function on(string $date): static
    {
        return $this->state(['date' => $date]);
    }
}
