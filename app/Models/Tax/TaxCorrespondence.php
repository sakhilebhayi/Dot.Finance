<?php

namespace App\Models\Tax;

use App\Casts\DateOnly;
use App\Models\Concerns\HasLedgerContext;
use App\Models\Shared\Attachment;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** A log of correspondence with SARS (letters, eFiling messages, calls): what, when, and by when a reply is due. */
class TaxCorrespondence extends Model
{
    use HasLedgerContext;

    protected $table = 'tax_correspondence';

    protected $fillable = [
        'user_id', 'team_id', 'direction', 'channel', 'reference', 'subject', 'summary', 'occurred_on', 'response_due_on', 'status',
        'attachment_id', 'created_by_user_id',
    ];

    protected $casts = ['occurred_on' => DateOnly::class, 'response_due_on' => DateOnly::class];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Correspondence is kept for the record; close it instead.'));
    }

    public function ledgerReferences(): array
    {
        return ['attachment_id' => Attachment::class];
    }
}
