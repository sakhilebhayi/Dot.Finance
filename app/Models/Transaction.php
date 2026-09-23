<?php

namespace App\Models;

use App\Models\Concerns\HasUserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    use HasUserScope;

    protected $fillable = [
        'account_id', 'category_id', 'user_id', 'description', 'amount', 'type', 'date', 'notes',
        'recurrence_type', 'recurrence_interval', 'next_occurrence_date', 'recurrence_parent_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'date' => 'date',
        'next_occurrence_date' => 'date',
        'recurrence_interval' => 'integer',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRecurring(): bool
    {
        return $this->recurrence_type !== null;
    }

    /** The original transaction this one was generated from, if any. */
    public function recurrenceParent(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'recurrence_parent_id');
    }

    /** Every occurrence generated from this transaction (only populated when this is the anchor). */
    public function recurrenceOccurrences(): HasMany
    {
        return $this->hasMany(Transaction::class, 'recurrence_parent_id');
    }
}
