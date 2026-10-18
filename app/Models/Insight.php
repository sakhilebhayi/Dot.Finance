<?php

namespace App\Models;

use App\Models\Concerns\HasLedgerContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A weekly rule-based summary of a user's ledger: fixed arithmetic and fixed
 * sentences (see GenerateInsights). It is not produced by an AI model.
 *
 * The table keeps its original name, ai_insights, because the database is shared
 * across the Dot ecosystem and renaming it could break a reader this app cannot
 * see. The name is a historical accident, not a description.
 *
 * iso_week ("2026-W42") is unique per context (a person or a company), so the weekly job can run any number
 * of times in a week and still leaves one summary. Rows from before this existed
 * may have a null iso_week (older duplicates of the same week).
 */
class Insight extends Model
{
    use HasLedgerContext;

    protected $table = 'ai_insights';

    protected $fillable = ['user_id', 'team_id', 'insight', 'type', 'iso_week'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
