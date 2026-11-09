<?php

namespace App\Models\Purchases;

use App\Casts\DateOnly;
use App\Contracts\Allocatable;
use App\Contracts\Approvable;
use App\Models\Concerns\HasLedgerContext;
use App\Models\Controls\ApprovalRequest;
use App\Models\Shared\Attachment;
use App\Models\Shared\Party;
use App\Models\Shared\PaymentAllocation;
use App\Models\User;
use App\Services\Purchases\BillWorkflow;
use App\Services\Purchases\PurchaseLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A supplier bill, or a supplier credit note (kind = credit_note). Server-computed totals; posted on approval
 * (Dr expense + Dr VAT input / Cr accounts payable) and then immutable: it can only be voided, with a reason.
 *
 * Statuses: draft, awaiting_approval, approved (approved but not yet posted: see post_error), open, partially_paid,
 * paid, void. "Paid" only ever comes from live payment allocations that cover what is owed.
 */
class Bill extends Model implements Allocatable, Approvable
{
    use HasLedgerContext;

    public const STATUSES = ['draft', 'awaiting_approval', 'approved', 'open', 'partially_paid', 'paid', 'void'];

    /** Statuses in which the bill is in the books. */
    public const POSTED = ['open', 'partially_paid', 'paid'];

    public const DOCUMENT_TYPES = ['tax_invoice', 'abridged_invoice', 'customs', 'credit_note', 'none', 'unknown'];

    protected $fillable = [
        'user_id', 'team_id', 'kind', 'party_id', 'number', 'supplier_reference', 'supplier_reference_norm', 'status', 'bill_date', 'due_date',
        'currency', 'fx_rate_e8', 'tax_inclusive', 'subtotal_cents', 'tax_cents', 'total_cents', 'claimable_tax_cents', 'base_total_cents',
        'document_type', 'supplier_vat_number', 'vat_review', 'rule_versions', 'rounding_level', 'tax_confidence', 'credit_for_bill_id',
        'credit_reason', 'early_payment_discount_bp', 'early_payment_days', 'posted_entry_id', 'posted_at', 'posted_by', 'void_entry_id',
        'voided_at', 'voided_by', 'void_reason', 'post_error', 'rejection_reason', 'source', 'recurring_bill_id', 'recurring_period',
        'client_token', 'duplicate_ack', 'created_by', 'submitted_by', 'submitted_at', 'notes', 'version',
    ];

    protected $casts = [
        'bill_date' => DateOnly::class,
        'due_date' => DateOnly::class,
        'fx_rate_e8' => 'integer',
        'tax_inclusive' => 'boolean',
        'subtotal_cents' => 'integer',
        'tax_cents' => 'integer',
        'total_cents' => 'integer',
        'claimable_tax_cents' => 'integer',
        'base_total_cents' => 'integer',
        'vat_review' => 'array',
        'rule_versions' => 'array',
        'duplicate_ack' => 'array',
        'early_payment_discount_bp' => 'integer',
        'early_payment_days' => 'integer',
        'posted_at' => 'datetime',
        'voided_at' => 'datetime',
        'submitted_at' => 'datetime',
        'version' => 'integer',
    ];

    protected $attributes = ['status' => 'draft', 'kind' => 'bill'];

    public function ledgerReferences(): array
    {
        return ['party_id' => Party::class, 'credit_for_bill_id' => self::class];
    }

    // ── relations ───────────────────────────────────────────────────────

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class)->withTrashed();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BillLine::class)->orderBy('line_no');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function creditedBill(): BelongsTo
    {
        return $this->belongsTo(self::class, 'credit_for_bill_id');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(self::class, 'credit_for_bill_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function allocations(): MorphMany
    {
        return $this->morphMany(PaymentAllocation::class, 'allocatable');
    }

    // ── state ───────────────────────────────────────────────────────────

    public function isCreditNote(): bool
    {
        return $this->kind === 'credit_note';
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPosted(): bool
    {
        return in_array($this->status, self::POSTED, true);
    }

    public function kindLabel(): string
    {
        return $this->isCreditNote() ? 'Credit note' : 'Bill';
    }

    /** The name to show: its number once it has one, else "Draft bill". */
    public function displayNumber(): string
    {
        return $this->number ?? ('Draft '.strtolower($this->kindLabel()));
    }

    public function isOverdue(?\DateTimeInterface $on = null): bool
    {
        $on = $on ? \Carbon\CarbonImmutable::instance($on) : now()->toImmutable();

        return in_array($this->status, ['open', 'partially_paid'], true) && $this->due_date !== null && $this->due_date->toDateString() < $on->toDateString();
    }

    // ── money ───────────────────────────────────────────────────────────

    /** Total of the posted credit notes linked to this bill, in the bill's currency. */
    public function creditedCents(): int
    {
        if ($this->isCreditNote()) {
            return 0;
        }

        return (int) self::query()->forContext($this->ledgerContext())->where('credit_for_bill_id', $this->id)
            ->where('kind', 'credit_note')->whereIn('status', self::POSTED)->sum('total_cents');
    }

    /** The bill's value in the ledger's base currency (what would be, or was, posted). */
    public function baseTotalCents(): int
    {
        return $this->base_total_cents ?? app(PurchaseLedger::class)->toBase($this->ledgerContext(), $this->total_cents, $this->currency, $this->fx_rate_e8);
    }

    // ── Allocatable ─────────────────────────────────────────────────────

    public function allocatableTotalCents(): int
    {
        if ($this->isCreditNote() || ! $this->isPosted()) {
            return 0;
        }

        return $this->total_cents - $this->creditedCents();
    }

    public function allocatableCurrency(): string
    {
        return $this->currency;
    }

    public function allocatablePartyId(): ?int
    {
        return $this->party_id;
    }

    public function allocatableDirection(): string
    {
        return 'out';
    }

    // ── Approvable ──────────────────────────────────────────────────────

    public function approvalSubjectKind(): string
    {
        return 'bill';
    }

    public function approvalAmountCents(): int
    {
        return $this->baseTotalCents();
    }

    public function approvalCurrency(): ?string
    {
        return app(PurchaseLedger::class)->baseCurrency($this->ledgerContext());
    }

    public function approvalLabel(): string
    {
        return $this->kindLabel().' '.($this->number ?? '#'.$this->id);
    }

    public function onApprovalDecided(ApprovalRequest $request): void
    {
        app(BillWorkflow::class)->approvalDecided($this, $request);
    }

    public function auditLabel(): string
    {
        return $this->kindLabel().' '.($this->number ?? '#'.$this->id).' '.\App\Support\Money::format((int) $this->total_cents, $this->currency);
    }
}
