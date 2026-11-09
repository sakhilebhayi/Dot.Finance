<?php

namespace App\Models\Shared;

use App\Casts\DateOnly;
use App\Models\Concerns\AppendsVersions;
use App\Models\Concerns\HasOptionalLedgerContext;
use Illuminate\Database\Eloquent\Model;

/**
 * A tax code a document line carries (ZA_VAT_STD, ZA_VAT_ZERO...): what kind of supply it is,
 * which return box it is reported in, and which rate key gives its rate on a date.
 * Global, or a ledger's own override. Versioned by date and append-only (see AppendsVersions);
 * only its display name, description, ordering and active flag may be edited.
 */
class TaxCode extends Model
{
    use AppendsVersions, HasOptionalLedgerContext;

    public const KINDS = ['standard', 'zero', 'exempt', 'out_of_scope', 'reverse_charge'];

    protected $fillable = [
        'user_id', 'team_id', 'jurisdiction', 'code', 'name', 'description', 'kind', 'applies_to', 'rate_ref',
        'box_output', 'box_input', 'deductible_policy', 'valid_from', 'valid_to', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'valid_from' => DateOnly::class,
        'valid_to' => DateOnly::class,
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function versionIdentity(): array
    {
        return ['jurisdiction', 'code'];
    }

    public function mutableColumns(): array
    {
        return ['valid_to', 'name', 'description', 'is_active', 'sort_order'];
    }

    /** A code that charges tax at a rate (kind standard); zero, exempt and out-of-scope codes charge none. */
    public function chargesTax(): bool
    {
        return $this->rate_ref !== null && in_array($this->kind, ['standard', 'reverse_charge'], true);
    }
}
