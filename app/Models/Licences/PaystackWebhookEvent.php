<?php

namespace App\Models\Licences;

use Illuminate\Database\Eloquent\Model;

class PaystackWebhookEvent extends Model
{
    protected $table = 'paystack_webhook_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }
}
