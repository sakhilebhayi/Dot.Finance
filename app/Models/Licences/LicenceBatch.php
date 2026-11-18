<?php

namespace App\Models\Licences;

use Illuminate\Database\Eloquent\Model;

/**
 * One licence purchase batch as InfoDot sent it. The price, quantity and interval are InfoDot's, copied once from the
 * signed body and never changed. Secrets (authorization, email token) are encrypted at rest and never logged.
 */
class LicenceBatch extends Model
{
    public const PENDING = 'pending';

    public const AWAITING_PAYMENT = 'awaiting_payment';

    public const ACTIVE = 'active';

    public const PAST_DUE = 'past_due';

    public const CANCELLED = 'cancelled';

    public const REFUNDED = 'refunded';

    protected $table = 'licence_batches';

    protected $guarded = [];

    protected $hidden = ['authorization_code', 'subscription_email_token'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer', 'total_minor' => 'integer', 'init_attempts' => 'integer', 'needs_review' => 'boolean',
            'authorization_code' => 'encrypted', 'subscription_email_token' => 'encrypted',
            'customer_address' => 'array', 'cancel_requested_at' => 'datetime', 'period_start' => 'datetime', 'period_end' => 'datetime', 'first_paid_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }
}
