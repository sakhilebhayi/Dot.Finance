<?php

namespace App\Models;

use App\Models\Concerns\HasUserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Budget extends Model
{
    use HasUserScope;

    protected $fillable = ['user_id', 'category_id', 'amount', 'month', 'year'];

    protected $casts = ['amount' => 'decimal:2'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function spentAmount(): float
    {
        return (float) Transaction::where('category_id', $this->category_id)
            ->where('user_id', $this->user_id)
            ->where('type', 'expense')
            ->whereMonth('date', $this->month)
            ->whereYear('date', $this->year)
            ->sum('amount');
    }
}
