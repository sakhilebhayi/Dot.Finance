<?php

namespace App\Models\Tax;

use App\Casts\DateOnly;
use App\Models\Concerns\HasLedgerContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reminder record produced by the TaxReminder service. Delivery (e-mail, bell, Dot.Notify) belongs to the
 * Intelligence module: it reads rows with delivered_at NULL and stamps delivered_at / delivered_via.
 */
class TaxReminderNotice extends Model
{
    use HasLedgerContext;

    protected $table = 'tax_reminders';

    protected $fillable = [
        'user_id', 'team_id', 'tax_deadline_id', 'kind', 'remind_on', 'title', 'body', 'severity', 'delivered_at', 'delivered_via',
        'dismissed_at', 'dismissed_by_user_id',
    ];

    protected $casts = ['remind_on' => DateOnly::class, 'delivered_at' => 'datetime', 'dismissed_at' => 'datetime'];

    public function ledgerReferences(): array
    {
        return ['tax_deadline_id' => TaxDeadline::class];
    }

    public function deadline(): BelongsTo
    {
        return $this->belongsTo(TaxDeadline::class, 'tax_deadline_id');
    }
}
