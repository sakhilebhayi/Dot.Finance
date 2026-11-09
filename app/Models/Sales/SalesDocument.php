<?php

namespace App\Models\Sales;

use App\Casts\DateOnly;
use App\Contracts\Allocatable;
use App\Contracts\Approvable;
use App\Models\Concerns\HasLedgerContext;
use App\Models\Controls\ApprovalRequest;
use App\Models\Shared\Party;
use App\Models\User;
use App\Services\Sales\DocumentIssuer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One document of the sales family: quote, pro forma, tax invoice, credit note or debit note.
 *
 * A draft is editable and has no number. Issuing gives it the next gap-free number, freezes it
 * (sealed_at) and, for invoices, credit notes and debit notes, posts it to the books. From then on only
 * the columns in AFTER_SEAL may change; everything that was issued is protected here and by database
 * triggers. A mistake is a void (with a reversing journal entry) or a credit note, never an edit.
 *
 * Money is in minor units of `currency`; base_* columns are the same amounts in the ledger's base
 * currency, fixed at the rate stored on the document.
 */
class SalesDocument extends Model implements Allocatable, Approvable
{
    use HasLedgerContext;

    public const KINDS = ['quote', 'pro_forma', 'tax_invoice', 'credit_note', 'debit_note'];

    public const STATUSES = ['draft', 'awaiting_approval', 'issued', 'partially_paid', 'paid', 'overdue', 'void', 'accepted', 'declined', 'expired'];

    /** @var list<string> the only columns that may change after the document is sealed */
    public const AFTER_SEAL = [
        'status', 'sent_at', 'viewed_at', 'accepted_at', 'declined_at', 'expired_at', 'voided_at', 'voided_by', 'void_reason',
        'void_entry_id', 'written_off_cents', 'written_off_base_cents', 'internal_note', 'converted_to_id', 'approval_note',
        'fx_rate_locked', 'version', 'updated_at', 'client_token',
    ];

    protected $guarded = [];

    protected $casts = [
        'issue_date' => DateOnly::class,
        'supply_date' => DateOnly::class,
        'due_date' => DateOnly::class,
        'valid_until' => DateOnly::class,
        'fx_rate_as_of' => DateOnly::class,
        'fx_rate_e8' => 'integer',
        'fx_rate_recorded_at' => 'datetime',
        'fx_rate_locked' => 'boolean',
        'prices_include_tax' => 'boolean',
        'vat_registered' => 'boolean',
        'tax_rule_versions' => 'array',
        'customer_snapshot' => 'array',
        'supplier_snapshot' => 'array',
        'net_cents' => 'integer',
        'discount_cents' => 'integer',
        'tax_cents' => 'integer',
        'total_cents' => 'integer',
        'base_net_cents' => 'integer',
        'base_tax_cents' => 'integer',
        'base_total_cents' => 'integer',
        'written_off_cents' => 'integer',
        'written_off_base_cents' => 'integer',
        'gl_entry_id' => 'integer',
        'sealed_at' => 'datetime',
        'issued_at' => 'datetime',
        'sent_at' => 'datetime',
        'viewed_at' => 'datetime',
        'accepted_at' => 'datetime',
        'declined_at' => 'datetime',
        'expired_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $document) {
            if ($document->getOriginal('sealed_at') === null) {
                return;
            }

            foreach (array_keys($document->getDirty()) as $column) {
                if (! in_array($column, self::AFTER_SEAL, true)) {
                    throw new LogicException("An issued document's {$column} cannot be changed. Void it, or issue a credit note.");
                }
            }

            if (in_array($document->status, ['draft', 'awaiting_approval'], true)) {
                throw new LogicException('An issued document cannot go back to draft.');
            }
        });

        static::deleting(function (self $document) {
            if ($document->sealed_at !== null) {
                throw new LogicException('An issued document is never deleted: void it, or issue a credit note.');
            }
        });
    }

    // ---- relations ---------------------------------------------------------------------------

    public function ledgerReferences(): array
    {
        return ['party_id' => Party::class, 'parent_id' => self::class, 'source_document_id' => self::class];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesDocumentLine::class, 'document_id')->orderBy('position');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class)->withTrashed();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SalesDocumentEvent::class, 'document_id')->orderBy('id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(SalesApplication::class, 'target_document_id');
    }

    // ---- scopes ------------------------------------------------------------------------------

    public function scopeOfKind(Builder $query, string ...$kinds): Builder
    {
        return $query->whereIn('kind', $kinds);
    }

    /** Invoice-like documents that put something into receivables: tax invoices and debit notes. */
    public function scopeReceivable(Builder $query): Builder
    {
        return $query->whereIn('kind', ['tax_invoice', 'debit_note']);
    }

    /** Issued and not void: the documents that count in the books. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNotNull('sealed_at')->where('status', '<>', 'void');
    }

    /** Issued receivables that can still be paid (not void, not fully paid): the status is a fast filter, the balance is the truth. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('kind', ['tax_invoice', 'debit_note'])->whereIn('status', ['issued', 'partially_paid']);
    }

    // ---- state -------------------------------------------------------------------------------

    public function isSealed(): bool
    {
        return $this->sealed_at !== null;
    }

    public function isDraft(): bool
    {
        return in_array($this->status, ['draft', 'awaiting_approval'], true);
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }

    /** A tax invoice or debit note: something the customer owes. */
    public function isReceivable(): bool
    {
        return in_array($this->kind, ['tax_invoice', 'debit_note'], true);
    }

    public function isCredit(): bool
    {
        return $this->kind === 'credit_note';
    }

    /** Documents that reach the books (a quote and a pro forma never do). */
    public function postsToBooks(): bool
    {
        return in_array($this->kind, ['tax_invoice', 'credit_note', 'debit_note'], true);
    }

    public function isQuote(): bool
    {
        return $this->kind === 'quote';
    }

    public function isForeign(): bool
    {
        return $this->currency !== $this->base_currency;
    }

    /** Past its due date and still owing. The stored status never says "overdue": it would drift the moment someone paid. */
    public function isOverdue(?CarbonImmutable $on = null): bool
    {
        $on ??= CarbonImmutable::today();

        return $this->isReceivable() && in_array($this->status, ['issued', 'partially_paid'], true)
            && $this->due_date !== null && $this->due_date->toDateString() < $on->toDateString();
    }

    public function displayStatus(?CarbonImmutable $on = null): string
    {
        return $this->isOverdue($on) ? 'overdue' : $this->status;
    }

    public function title(?bool $vatRegistered = null): string
    {
        $registered = $vatRegistered ?? $this->vat_registered;

        return match ($this->kind) {
            'quote' => 'Quote',
            'pro_forma' => 'Pro forma invoice',
            'tax_invoice' => $registered ? 'Tax invoice' : 'Invoice',
            'credit_note' => 'Credit note',
            'debit_note' => 'Debit note',
            default => ucfirst(str_replace('_', ' ', $this->kind)),
        };
    }

    public function reference(): string
    {
        return $this->number ?? ('Draft #'.$this->id);
    }

    /** The numbering series a kind draws from. */
    public static function seriesFor(string $kind): string
    {
        return match ($kind) {
            'tax_invoice' => 'invoice',
            default => $kind,
        };
    }

    // ---- Allocatable -------------------------------------------------------------------------

    /**
     * What is owed in total: the document total less the credit notes applied to it and any authorised write-off.
     * PaymentAllocator subtracts the live payment allocations from this to get the open balance.
     */
    public function allocatableTotalCents(): int
    {
        $credited = (int) SalesApplication::query()->forContext($this->ledgerContext())
            ->where('target_document_id', $this->id)->where('kind', 'credit')->whereNull('released_at')->sum('amount_cents');

        return (int) $this->total_cents - $credited - (int) $this->written_off_cents;
    }

    public function allocatableCurrency(): string
    {
        return $this->currency;
    }

    public function allocatablePartyId(): ?int
    {
        return $this->party_id !== null ? (int) $this->party_id : null;
    }

    public function allocatableDirection(): string
    {
        return 'in';
    }

    // ---- Approvable --------------------------------------------------------------------------

    public function approvalSubjectKind(): string
    {
        return 'invoice';
    }

    public function approvalAmountCents(): int
    {
        return (int) $this->base_total_cents;
    }

    public function approvalCurrency(): ?string
    {
        return $this->base_currency;
    }

    public function approvalLabel(): string
    {
        return $this->title().' '.$this->reference();
    }

    public function onApprovalDecided(ApprovalRequest $request): void
    {
        app(DocumentIssuer::class)->approvalDecided($this, $request);
    }

    public function auditLabel(): string
    {
        return $this->title().' '.$this->reference();
    }
}
