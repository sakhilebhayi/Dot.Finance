<?php

namespace App\Models;

use App\Models\Concerns\HasUserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use HasUserScope;

    protected $fillable = ['user_id', 'name', 'type', 'currency', 'balance', 'institution'];

    protected $casts = ['balance' => 'decimal:2'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class)->latest('date');
    }
}
