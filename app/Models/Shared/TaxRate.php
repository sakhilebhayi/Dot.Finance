<?php

namespace App\Models\Shared;

use App\Casts\DateOnly;
use App\Models\Concerns\AppendsVersions;
use App\Models\Concerns\HasOptionalLedgerContext;
use Illuminate\Database\Eloquent\Model;

/** The rate (in basis points: 1500 = 15 %) a tax code's rate_ref has on a date. Versioned, append-only, global or per ledger. */
class TaxRate extends Model
{
    use AppendsVersions, HasOptionalLedgerContext;

    protected $fillable = [
        'user_id', 'team_id', 'jurisdiction', 'rate_key', 'rate_bp', 'valid_from', 'valid_to', 'legal_status',
        'source_url', 'source_ref', 'accessed_at', 'confidence', 'note',
    ];

    protected $casts = [
        'rate_bp' => 'integer',
        'valid_from' => DateOnly::class,
        'valid_to' => DateOnly::class,
        'accessed_at' => DateOnly::class,
    ];

    public function versionIdentity(): array
    {
        return ['jurisdiction', 'rate_key'];
    }

    public function mutableColumns(): array
    {
        return ['valid_to'];
    }
}
