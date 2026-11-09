<?php

namespace App\Models\Purchases;

use App\Casts\DateOnly;
use App\Contracts\Allocatable;
use App\Contracts\Approvable;
use App\Models\Concerns\HasLedgerContext;
use App\Models\Controls\ApprovalRequest;
use App\Models\Shared\Party;
use App\Models\User;
use App\Services\Purchases\ClaimWorkflow;
use App\Services\Purchases\PurchaseLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reimbursement claim: a member of the ledger spent their own money on the business and asks to be paid back.
 * Each line is one receipt. On approval it posts Dr expense (+ Dr VAT input where the receipt allows it) / Cr accounts payable,
 * in the name of a supplier-type party that stands for the claimant, so paying it back is an ordinary supplier payment and the
 * accounts-payable ledger still ties to the books. Claims are in the ledger's base currency.
 */
class ExpenseClaim extends Model implements Allocatable, Approvable
{
    use HasLedgerContext;

    protected $fillable = [
        'user_id', 'team_id', 'claimant_user_id', 'party_id', 'title', 'number', 'status', 'claim_date', 'currency', 'subtotal_cents', 'tax_cents',
        'total_cents', 'claimable_tax_cents', 'vat_review', 'rule_versions', 'posted_entry_id', 'posted_at', 'posted_by', 'void_entry_id',
        'voided_at', 'voided_by', 'void_reason', 'post_error', 'rejection_reason', 'client_token', 'created_by', 'submitted_at', 'notes', 'version',
    ];

    protected $casts = [
        'claim_date' => DateOnly::class,
        'subtotal_cents' => 'integer',
        'tax_cents' => 'integer',
        'total_cents' => 'integer',
        'claimable_tax_cents' => 'integer',
        'vat_review' => 'array',
        'rule_versions' => 'array',
        'posted_at' => 'datetime',
        'voided_at' => 'datetime',
        'submitted_at' => 'datetime',
        'version' => 'integer',
    ];

    protected $attributes = ['status' => 'draft'];

    public function ledgerReferences(): array
    {
        return ['party_id' => Party::class];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ExpenseClaimLine::class)->orderBy('line_no');
    }

    public function claimant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimant_user_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class)->withTrashed();
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPosted(): bool
    {
        return in_array($this->status, Bill::POSTED, true);
    }

    public function displayNumber(): string
    {
        return $this->number ?? 'Draft claim';
    }

    // ── Allocatable ─────────────────────────────────────────────────────

    public function allocatableTotalCents(): int
    {
        return $this->isPosted() ? $this->total_cents : 0;
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
        return 'expense';
    }

    public function approvalAmountCents(): int
    {
        return $this->total_cents;
    }

    public function approvalCurrency(): ?string
    {
        return app(PurchaseLedger::class)->baseCurrency($this->ledgerContext());
    }

    public function approvalLabel(): string
    {
        return 'Expense claim '.($this->number ?? '#'.$this->id);
    }

    public function onApprovalDecided(ApprovalRequest $request): void
    {
        app(ClaimWorkflow::class)->approvalDecided($this, $request);
    }

    public function auditLabel(): string
    {
        return 'Expense claim '.($this->number ?? '#'.$this->id).' '.\App\Support\Money::format((int) $this->total_cents, $this->currency);
    }
}
