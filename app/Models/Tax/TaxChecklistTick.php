<?php

namespace App\Models\Tax;

use App\Models\Concerns\HasLedgerContext;
use Illuminate\Database\Eloquent\Model;

/** A person's tick against one item of an obligation's checklist. */
class TaxChecklistTick extends Model
{
    use HasLedgerContext;

    public $timestamps = false;

    protected $fillable = ['user_id', 'team_id', 'tax_deadline_id', 'item_key', 'note', 'ticked_by_user_id', 'ticked_at'];

    protected $casts = ['ticked_at' => 'datetime'];

    public function ledgerReferences(): array
    {
        return ['tax_deadline_id' => TaxDeadline::class];
    }
}
