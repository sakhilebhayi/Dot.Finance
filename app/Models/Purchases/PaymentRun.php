<?php

namespace App\Models\Purchases;

use App\Casts\DateOnly;
use App\Contracts\Approvable;
use App\Models\Account;
use App\Models\Concerns\HasLedgerContext;
use App\Models\Controls\ApprovalRequest;
use App\Services\Purchases\PaymentRunService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A batch payment of the bills that fall due by a date, executed once (idempotent) and exported as a generic CSV. */
class PaymentRun extends Model implements Approvable
{
    use HasLedgerContext;

    protected $fillable = [
        'user_id', 'team_id', 'name', 'due_by', 'paid_on', 'cash_account_id', 'currency', 'status', 'idempotency_key', 'total_cents',
        'supplier_count', 'executed_at', 'executed_by', 'csv_exported_at', 'created_by',
    ];

    protected $casts = [
        'due_by' => DateOnly::class,
        'paid_on' => DateOnly::class,
        'total_cents' => 'integer',
        'supplier_count' => 'integer',
        'executed_at' => 'datetime',
        'csv_exported_at' => 'datetime',
    ];

    public function ledgerReferences(): array
    {
        return ['cash_account_id' => Account::class];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PaymentRunItem::class);
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'cash_account_id')->withTrashed();
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    // ── Approvable: the run is the thing approved when a policy covers payments (it is in the ledger's base currency) ──

    public function approvalSubjectKind(): string
    {
        return 'payment';
    }

    public function approvalAmountCents(): int
    {
        return $this->total_cents;
    }

    public function approvalCurrency(): ?string
    {
        return $this->currency;
    }

    public function approvalLabel(): string
    {
        return 'Payment run #'.$this->id.' ('.$this->due_by?->toDateString().')';
    }

    public function onApprovalDecided(ApprovalRequest $request): void
    {
        app(PaymentRunService::class)->approvalDecided($this, $request);
    }

    public function auditLabel(): string
    {
        return 'Payment run #'.$this->id.' '.\App\Support\Money::format((int) $this->total_cents, $this->currency);
    }
}
