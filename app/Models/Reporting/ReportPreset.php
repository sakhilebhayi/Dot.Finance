<?php

namespace App\Models\Reporting;

use App\Models\Concerns\HasLedgerContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A named set of report parameters (period preset, comparison, basis, filters) that a person saved to reuse. */
class ReportPreset extends Model
{
    use HasLedgerContext;

    protected $fillable = ['user_id', 'team_id', 'created_by', 'name', 'report_key', 'params'];

    protected function casts(): array
    {
        return ['params' => 'array'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
