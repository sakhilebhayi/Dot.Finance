<?php

namespace App\Models\Licences;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicencePayment extends Model
{
    protected $table = 'licence_payments';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'refunded_minor' => 'integer', 'paid_at' => 'datetime', 'period_start' => 'datetime', 'period_end' => 'datetime'];
    }

    /** @return BelongsTo<LicenceBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(LicenceBatch::class, 'licence_batch_id');
    }
}
