<?php

namespace App\Models\Purchases;

use App\Casts\DateOnly;
use App\Models\Concerns\HasLedgerContext;
use App\Models\Shared\Party;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One version of a supplier's bank details. The details themselves are encrypted at rest (payload); the plain columns
 * carry only the bank name and the last four digits. A change is a NEW row that stays 'pending' until someone with the
 * authority to approve payments (and not the person who asked) activates it; the previous active row becomes
 * 'superseded'. Rows are never deleted and the sensitive payload never changes after creation.
 */
class SupplierBankDetail extends Model
{
    use HasLedgerContext;

    public const STATUSES = ['pending', 'active', 'rejected', 'superseded'];

    public const FIELDS = ['bank_name', 'account_holder', 'account_number', 'branch_code', 'account_type', 'swift', 'iban'];

    protected $fillable = [
        'user_id', 'team_id', 'party_id', 'version', 'status', 'payload', 'bank_name', 'account_last4', 'account_hash', 'change_reason',
        'verified_by_callback', 'requested_by', 'requested_at', 'decided_by', 'decided_at', 'decision_note', 'self_approved', 'activated_at',
    ];

    protected $casts = [
        'payload' => 'encrypted:array',
        'verified_by_callback' => 'boolean',
        'self_approved' => 'boolean',
        'requested_at' => 'datetime',
        'decided_at' => 'datetime',
        'activated_at' => 'datetime',
    ];

    /** The payload is not in a serialised copy of the row (a log line, a JSON response). */
    protected $hidden = ['payload', 'account_hash'];

    protected static function booted(): void
    {
        static::updating(function (self $row) {
            if ($row->isDirty(['payload', 'account_last4', 'account_hash', 'party_id', 'version', 'change_reason', 'requested_by', 'requested_at', 'bank_name'])) {
                throw new LogicException('Bank details are never edited in place: a change is a new version that has to be approved.');
            }
        });

        static::deleting(fn () => throw new LogicException('Bank detail versions are kept for the audit trail and never deleted.'));
    }

    public function ledgerReferences(): array
    {
        return ['party_id' => Party::class];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class)->withTrashed();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /** "FNB, account ending 4321": all the screen ever shows of an account. */
    public function masked(): string
    {
        return trim(($this->bank_name ? $this->bank_name.', ' : '').'account ending '.$this->account_last4);
    }
}
