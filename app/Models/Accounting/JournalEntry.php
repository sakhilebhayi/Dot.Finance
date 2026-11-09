<?php

namespace App\Models\Accounting;

use App\Casts\DateOnly;
use App\Models\Concerns\HasLedgerContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A posted journal entry. Append-only: the model refuses update() and delete(); the database refuses
 * them too (a trigger on both engines). The one change an entry ever undergoes, being marked
 * reversed by its reversal, is done by JournalPoster with a guarded query, not through this model.
 */
class JournalEntry extends Model
{
    use HasLedgerContext;

    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'entry_date' => DateOnly::class,
        'entry_number' => 'integer',
        'source_version' => 'integer',
        'total_cents' => 'integer',
        'posted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Journal entries are append-only: post a reversing entry instead.'));
        static::deleting(fn () => throw new LogicException('Journal entries are append-only: post a reversing entry instead.'));
    }

    public function ledgerReferences(): array
    {
        return ['period_id' => AccountingPeriod::class];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('line_no');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'period_id');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_entry_id');
    }

    public function isReversed(): bool
    {
        return $this->status === 'reversed';
    }

    public function isReversal(): bool
    {
        return $this->source_type === 'reversal';
    }

    public function reference(): string
    {
        return 'JE-'.str_pad((string) $this->entry_number, 6, '0', STR_PAD_LEFT);
    }
}
