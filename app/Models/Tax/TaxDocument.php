<?php

namespace App\Models\Tax;

use App\Casts\DateOnly;
use App\Models\Concerns\HasLedgerContext;
use App\Models\Shared\Attachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** An entry in the tax document vault: what the document is, which file holds it, and how long it must be kept. Never deleted. */
class TaxDocument extends Model
{
    use HasLedgerContext;

    public const CATEGORIES = [
        'vat_return_proof' => 'VAT201 proof of submission',
        'payment_proof' => 'Proof of payment to SARS',
        'sars_notice' => 'SARS notice or letter',
        'assessment' => 'Notice of assessment',
        'income_tax_return' => 'Income tax return and working paper',
        'payroll_certificate' => 'Payroll certificate (IRP5 / IT3(a))',
        'emp_reconciliation' => 'EMP201 / EMP501 submission',
        'supplier_invoice' => 'Supplier tax invoice',
        'registration' => 'Registration certificate',
        'contract' => 'Contract or agreement',
        'other' => 'Other tax record',
    ];

    protected $fillable = [
        'user_id', 'team_id', 'category', 'title', 'period_label', 'tax_deadline_id', 'attachment_id', 'document_date', 'retention_until',
        'legal_hold', 'retention_basis', 'notes', 'added_by_user_id', 'archived_at',
    ];

    protected $casts = ['document_date' => DateOnly::class, 'retention_until' => DateOnly::class, 'legal_hold' => 'boolean', 'archived_at' => 'datetime'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Tax records are archived, never deleted: they are kept for the statutory period.'));
    }

    public function ledgerReferences(): array
    {
        return ['tax_deadline_id' => TaxDeadline::class, 'attachment_id' => Attachment::class];
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }
}
