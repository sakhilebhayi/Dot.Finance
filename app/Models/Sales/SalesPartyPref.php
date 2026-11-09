<?php

namespace App\Models\Sales;

use App\Models\Concerns\HasLedgerContext;
use App\Models\Shared\Party;
use Illuminate\Database\Eloquent\Model;

/** Sales settings that belong to one customer: today, whether they have opted out of payment reminders. */
class SalesPartyPref extends Model
{
    use HasLedgerContext;

    protected $guarded = [];

    protected $casts = ['reminders_opt_out' => 'boolean', 'opted_out_at' => 'datetime', 'last_statement_sent_at' => 'datetime'];

    public function ledgerReferences(): array
    {
        return ['party_id' => Party::class];
    }
}
