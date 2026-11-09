<?php

namespace App\Models\Shared;

use App\Models\Concerns\HasLedgerContext;
use Illuminate\Database\Eloquent\Model;

/** The counter behind one kind of numbered document in one ledger. Only Numbering moves next_number. */
class DocumentSequence extends Model
{
    use HasLedgerContext;

    protected $fillable = ['user_id', 'team_id', 'doc_type', 'prefix', 'next_number', 'padding', 'year_reset', 'current_year', 'last_issued_at'];

    protected $casts = [
        'next_number' => 'integer',
        'padding' => 'integer',
        'year_reset' => 'boolean',
        'current_year' => 'integer',
        'last_issued_at' => 'datetime',
    ];
}
