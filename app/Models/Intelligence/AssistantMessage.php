<?php

namespace App\Models\Intelligence;

use App\Models\Concerns\HasLedgerContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One question and its answer in one ledger, kept for the person who asked (and only for them: see
 * scopeAskedBy). The structured answer is stored with the sentence, so what a person was told can always
 * be traced to the figures behind it. A person can delete any of their own exchanges.
 */
class AssistantMessage extends Model
{
    use HasLedgerContext;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'team_id', 'asker_id', 'question', 'intent', 'status', 'answer_text', 'answer', 'basis', 'model_used', 'model_rejected'];

    protected $casts = ['answer' => 'array', 'model_used' => 'boolean', 'model_rejected' => 'boolean'];

    public function scopeAskedBy($query, User|int $user)
    {
        return $query->where($this->getTable().'.asker_id', $user instanceof User ? $user->id : $user);
    }

    public function asker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asker_id');
    }

    public function drafts(): HasMany
    {
        return $this->hasMany(AssistantDraft::class, 'message_id');
    }
}
