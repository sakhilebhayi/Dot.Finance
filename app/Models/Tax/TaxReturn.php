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

    /** Added in QA (D-1301): the JSON documents and ids the working paper is printed from, frozen with the rest. */
    public const FROZEN_DOCUMENTS = ['user_id', 'team_id', 'period_label', 'channel', 'rule_versions', 'figures', 'reconciliation', 'checks', 'prepared_by_user_id', 'supersedes_return_id'];

    /** Frozen once a payment is recorded. */
    public const FROZEN_PAYMENT = ['paid_cents', 'paid_on', 'payment_reference', 'paid_by_user_id', 'payment_proof_attachment_id'];

    /** Frozen while a return is prepared (withdraw it to change anything). */
    public const FROZEN_WHILE_PREPARED = ['kind', 'period_start', 'period_end', 'revision', 'basis', 'channel', 'period_label', 'figures_hash', 'figures', 'rule_versions', 'reconciliation', 'checks',
        'output_tax_cents', 'input_tax_cents', 'payable_cents', 'prepared_by_user_id', 'prepared_at', 'user_id', 'team_id'];

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
            $changed = fn (string $column): bool => $return->isDirty($column) && self::printable($return->getOriginal($column)) !== self::printable($return->getAttribute($column));

            if (in_array($was, ['submitted', 'paid'], true)) {
                foreach (array_merge(self::FROZEN, self::FROZEN_DOCUMENTS) as $column) {
                    if ($changed($column)) {
                        throw new LogicException("A return recorded as submitted is frozen: {$column} never changes. Record a revision instead.");
                    }
                }
                if (! in_array($return->status, ['submitted', 'paid'], true) || ($was === 'paid' && $return->status !== 'paid')) {
                    throw new LogicException('A submitted return cannot go back to an earlier status.');
                }
            }

            if ($was === 'paid') {
                foreach (self::FROZEN_PAYMENT as $column) {
                    if ($changed($column)) {
                        throw new LogicException("A recorded payment never changes: {$column}.");
                    }
                }
            }

            if ($was === 'prepared' && $return->status === 'prepared') {
                foreach (self::FROZEN_WHILE_PREPARED as $column) {
                    if ($changed($column)) {
                        throw new LogicException("A prepared return has frozen figures: {$column} cannot change. Withdraw the preparation first.");
                    }
                }
            }

            if ($return->getOriginal('settlement_entry_id') !== null && $changed('settlement_entry_id')) {
                throw new LogicException('The settlement journal of a return is set once and never changed.');
            }
        });

        static::deleting(function (self $return) {
            if ($return->status !== 'draft') {
                throw new LogicException('Only a draft return can be deleted.');
            }
        });
    }

    /** A comparable form of a column value: arrays by their JSON, everything else as a string. */
    private static function printable(mixed $value): string
    {
        return is_array($value) ? (string) json_encode($value) : (string) $value;
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
}
