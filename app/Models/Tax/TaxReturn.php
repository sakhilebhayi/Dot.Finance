<?php

namespace App\Models\Tax;

use App\Casts\DateOnly;
use App\Models\Concerns\HasLedgerContext;
use App\Models\Shared\Attachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A VAT201 or EMP201 prepared in Dot.Finance. Statuses:
 *
 *   draft      the figures are recomputed from the books every time they are opened; adjustments can be added
 *   prepared   figures frozen with a hash and the rule versions used; working paper stamped PREPARED, NOT SUBMITTED
 *   submitted  a PERSON recorded the SARS reference after filing it themselves on eFiling; frozen for good
 *   paid       a person recorded the payment
 *
 * Dot.Finance never submits anything to SARS. Once a return is submitted the model and the database both refuse
 * to change its figures, period or reference; a correction is a revision (a new row that supersedes this one).
 */
class TaxReturn extends Model
{
    use HasLedgerContext;

    public const FROZEN = [
        'kind', 'period_start', 'period_end', 'revision', 'basis', 'figures_hash', 'output_tax_cents', 'input_tax_cents',
        'payable_cents', 'sars_reference', 'submitted_on', 'submitted_by_user_id', 'prepared_at',
    ];

    protected $fillable = [
        'user_id', 'team_id', 'kind', 'period_start', 'period_end', 'period_label', 'revision', 'supersedes_return_id', 'status',
        'basis', 'channel', 'rule_versions', 'figures', 'figures_hash', 'output_tax_cents', 'input_tax_cents', 'payable_cents',
        'reconciliation', 'checks', 'prepared_by_user_id', 'prepared_at', 'export_count', 'last_exported_at', 'sars_reference',
        'submitted_on', 'submitted_by_user_id', 'submission_proof_attachment_id', 'submission_note', 'paid_cents', 'paid_on',
        'payment_reference', 'paid_by_user_id', 'payment_proof_attachment_id', 'settlement_entry_id',
    ];

    protected $casts = [
        'period_start' => DateOnly::class,
        'period_end' => DateOnly::class,
        'submitted_on' => DateOnly::class,
        'paid_on' => DateOnly::class,
        'prepared_at' => 'datetime',
        'last_exported_at' => 'datetime',
        'rule_versions' => 'array',
        'figures' => 'array',
        'reconciliation' => 'array',
        'checks' => 'array',
        'output_tax_cents' => 'integer',
        'input_tax_cents' => 'integer',
        'payable_cents' => 'integer',
        'paid_cents' => 'integer',
        'revision' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $return) {
            $was = $return->getOriginal('status');

            if (in_array($was, ['submitted', 'paid'], true)) {
                foreach (self::FROZEN as $column) {
                    if ($return->isDirty($column) && (string) $return->getOriginal($column) !== (string) $return->getAttribute($column)) {
                        throw new LogicException("A return recorded as submitted is frozen: {$column} never changes. Record a revision instead.");
                    }
                }
                if (! in_array($return->status, ['submitted', 'paid'], true) || ($was === 'paid' && $return->status !== 'paid')) {
                    throw new LogicException('A submitted return cannot go back to an earlier status.');
                }
            }
        });

        static::deleting(function (self $return) {
            if ($return->status !== 'draft') {
                throw new LogicException('Only a draft return can be deleted.');
            }
        });
    }

    public function ledgerReferences(): array
    {
        return [
            'supersedes_return_id' => self::class,
            'submission_proof_attachment_id' => Attachment::class,
            'payment_proof_attachment_id' => Attachment::class,
        ];
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(TaxReturnAdjustment::class)->orderBy('id');
    }

    public function activeAdjustments(): HasMany
    {
        return $this->hasMany(TaxReturnAdjustment::class)->whereNull('voided_at')->orderBy('id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_return_id');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPrepared(): bool
    {
        return $this->status === 'prepared';
    }

    public function isSubmitted(): bool
    {
        return in_array($this->status, ['submitted', 'paid'], true);
    }

    public function kindLabel(): string
    {
        return $this->kind === 'vat201' ? 'VAT201' : 'EMP201';
    }

    /** The plain status wording used everywhere. Never "submitted to SARS": it is recorded by a person. */
    public function statusLabel(): string
    {
        return match ($this->status) {
            'draft' => 'Draft',
            'prepared' => 'Prepared, not submitted',
            'submitted' => 'Recorded as submitted by a person',
            'paid' => 'Submission and payment recorded',
            default => $this->status,
        };
    }

    /** The period in plain words (the stored label may carry a SARS category letter). */
    public function periodText(): string
    {
        return \App\Services\Tax\TaxLabels::plainPeriod($this->period_label, $this->period_start, $this->period_end);
    }
}
