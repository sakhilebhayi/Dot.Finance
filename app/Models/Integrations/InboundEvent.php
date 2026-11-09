<?php

namespace App\Models\Integrations;

use App\Models\Concerns\HasLedgerContext;
use Illuminate\Database\Eloquent\Model;

/** The receipt for one signed delivery from another platform, so a replay of the same delivery is recognised. */
class InboundEvent extends Model
{
    use HasLedgerContext;

    public const UPDATED_AT = null;

    protected $table = 'integration_inbound_events';

    protected $fillable = ['user_id', 'team_id', 'source', 'external_id', 'payload_sha256', 'status', 'summary'];

    protected $casts = ['summary' => 'array'];
}
