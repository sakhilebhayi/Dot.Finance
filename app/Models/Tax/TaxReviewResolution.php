<?php

namespace App\Models\Tax;

use App\Models\Accounting\JournalLine;
use App\Models\Concerns\HasLedgerContext;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** A person's decision on one flagged journal line in the VAT review queue (accepted as booked, corrected, ignored), with a note. Append-only. */
class TaxReviewResolution extends Model
{
    use HasLedgerContext;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'team_id', 'journal_line_id', 'issue_code', 'resolution', 'note', 'resolved_by_user_id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A review decision is kept as made.'));
        static::deleting(fn () => throw new LogicException('A review decision is kept as made.'));
    }

    public function ledgerReferences(): array
    {
        return ['journal_line_id' => JournalLine::class];
    }
}
