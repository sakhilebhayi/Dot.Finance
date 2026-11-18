<?php

namespace App\Models\Licences;

use Illuminate\Database\Eloquent\Model;

/** One Paystack plan per exact (amount, currency, interval). Never edited after creation: a new amount is a new plan. */
class PaystackPlan extends Model
{
    protected $table = 'paystack_plans';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }
}
