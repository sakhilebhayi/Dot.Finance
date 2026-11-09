<?php

namespace App\Models\Intelligence;

use App\Models\Concerns\HasLedgerContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Whether one person wants one kind of notification in one ledger, and where. No row means the defaults. */
class NotificationPreference extends Model
{
    use HasLedgerContext;

    /** What a person gets when they have never chosen: in the app and by e-mail, not forwarded to Dot.Notify. */
    public const DEFAULTS = ['in_app' => true, 'email' => true, 'notify' => false];

    protected $fillable = ['user_id', 'team_id', 'member_user_id', 'kind', 'in_app', 'email', 'notify'];

    protected $casts = ['in_app' => 'boolean', 'email' => 'boolean', 'notify' => 'boolean'];

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_user_id');
    }
}
