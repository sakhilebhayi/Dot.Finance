<?php

namespace App\Models;

use App\Models\Concerns\HasUserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiInsight extends Model
{
    use HasUserScope;

    protected $fillable = ['user_id', 'insight', 'type'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
