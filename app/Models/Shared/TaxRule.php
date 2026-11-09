<?php

namespace App\Models\Shared;

use App\Casts\DateOnly;
use App\Models\Concerns\AppendsVersions;
use App\Models\Concerns\HasOptionalLedgerContext;
use Illuminate\Database\Eloquent\Model;

/**
 * One version of one tax rule: a rate, a threshold, a table of bands, a due-date rule.
 * The value is data (json) typed by value_type; money is integer cents, rates are basis
 * points. Append-only: a new value is a new row with a later valid_from, and the previous
 * row is closed with valid_to. Every row names its source, when it was read, and how
 * sure we are (confidence), and carries a legal status so a proposal is not calculated with.
 */
class TaxRule extends Model
{
    use AppendsVersions, HasOptionalLedgerContext;

    /**
     * Legal statuses a calculation may use: law (enacted), law applied by the authority before promulgation
     * (announced), and a Dot.Finance default that is not law at all (policy, e.g. the rounding level).
     * proposed, draft and repealed are stored but never resolved.
     */
    public const USABLE_STATUSES = ['enacted', 'announced', 'policy'];

    protected $fillable = [
        'user_id', 'team_id', 'jurisdiction', 'tax_type', 'rule_key', 'qualifier', 'value_type', 'value', 'unit',
        'valid_from', 'valid_to', 'legal_status', 'source_url', 'source_ref', 'accessed_at', 'confidence', 'note',
        'supersedes_id', 'reviewed_by', 'review_due',
    ];

    protected $casts = [
        'value' => 'array',
        'valid_from' => DateOnly::class,
        'valid_to' => DateOnly::class,
        'accessed_at' => DateOnly::class,
        'review_due' => DateOnly::class,
    ];

    public function versionIdentity(): array
    {
        return ['jurisdiction', 'rule_key', 'qualifier'];
    }

    public function isUsable(): bool
    {
        return in_array($this->legal_status, self::USABLE_STATUSES, true);
    }
}
