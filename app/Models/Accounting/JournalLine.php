<?php

namespace App\Models\Accounting;

use App\Casts\DateOnly;
use App\Models\Account;
use App\Models\Category;
use App\Models\Concerns\HasLedgerContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** One line of a posted entry. Append-only (see JournalEntry). */
class JournalLine extends Model
{
    use HasLedgerContext;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'entry_date' => DateOnly::class,
        'debit_cents' => 'integer',
        'credit_cents' => 'integer',
        'txn_amount_cents' => 'integer',
        'fx_rate_e8' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Journal lines are append-only: post a reversing entry instead.'));
        static::deleting(fn () => throw new LogicException('Journal lines are append-only: post a reversing entry instead.'));
    }

    public function ledgerInheritsFrom(): ?string
    {
        return 'journal_entry_id';
    }

    public function ledgerReferences(): array
    {
        return [
            'journal_entry_id' => JournalEntry::class,
            'gl_account_id' => GlAccount::class,
            'cash_account_id' => Account::class,
            'category_id' => Category::class,
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class);
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'cash_account_id')->withTrashed();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class)->withTrashed();
    }

    /** Debit as positive, credit as negative. */
    public function signedCents(): int
    {
        return $this->debit_cents - $this->credit_cents;
    }
}
