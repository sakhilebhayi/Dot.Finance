<?php

namespace App\Models\Accounting;

use App\Models\Concerns\HasLedgerContext;
use Illuminate\Database\Eloquent\Model;

/** One dry run, apply or verification of the cash ledger into the journals: resumable and auditable. */
class GlBackfillRun extends Model
{
    use HasLedgerContext;

    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'exceptions' => 'array',
        'report' => 'array',
        'cursor_id' => 'integer',
    ];
}
