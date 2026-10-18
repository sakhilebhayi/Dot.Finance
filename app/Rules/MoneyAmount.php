<?php

namespace App\Rules;

use App\Support\Money;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A positive decimal amount with at most two decimal places, checked as a
 * string so "1e3", "0x10" and "12.345" never reach the ledger. Replaces
 * Laravel's `numeric` rule, which accepts exponent notation and floats.
 */
class MoneyAmount implements ValidationRule
{
    public function __construct(private readonly bool $allowZero = false, private readonly bool $allowNegative = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! Money::isValid($value)) {
            $fail('The :attribute must be an amount with at most two decimal places, for example 1234.50.');

            return;
        }

        $cents = Money::parse((string) $value);

        if (! $this->allowNegative && $cents < 0) {
            $fail('The :attribute cannot be negative.');
        } elseif (! $this->allowZero && $cents === 0) {
            $fail('The :attribute must be greater than zero.');
        }
    }
}
