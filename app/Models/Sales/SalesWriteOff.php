<?php

namespace App\Models\Sales;

use App\Contracts\Approvable;
use App\Models\Concerns\HasLedgerContext;
use App\Models\Controls\ApprovalRequest;
use App\Services\Sales\WriteOffs;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An authorised manual settlement of what a customer will not pay. It needs a reason and a person with the
 * approval capability; when an approval policy covers it, it waits for that approval first. It posts
 * Dr bad debts / Cr receivables when applied. (VAT bad-debt relief is a tax decision and is not made here.)
 */
class SalesWriteOff extends Model implements Approvable
{
    use HasLedgerContext;

    protected $guarded = [];

    protected $casts = [
        'amount_cents' => 'integer',
        'base_cents' => 'integer',
        'applied_at' => 'datetime',
    ];

    public function ledgerReferences(): array
    {
        return ['document_id' => SalesDocument::class];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class, 'document_id');
    }

    public function approvalSubjectKind(): string
    {
        return 'invoice';
    }

    public function approvalAmountCents(): int
    {
        return (int) $this->base_cents;
    }

    public function approvalCurrency(): ?string
    {
        return $this->document?->base_currency;
    }

    public function approvalLabel(): string
    {
        return 'Write-off of '.($this->document?->reference() ?? 'invoice');
    }

    public function onApprovalDecided(ApprovalRequest $request): void
    {
        app(WriteOffs::class)->approvalDecided($this, $request);
    }
}
