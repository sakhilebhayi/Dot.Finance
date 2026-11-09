<?php

namespace App\Models\Shared;

use App\Models\Concerns\HasLedgerContext;
use Database\Factories\Shared\PartyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A customer, a supplier or both. Belongs to one ledger; archived (soft-deleted), never destroyed,
 * because invoices, bills and payments will point at it.
 */
class Party extends Model
{
    /** @use HasFactory<PartyFactory> */
    use HasFactory, HasLedgerContext, SoftDeletes;

    public const TYPES = ['customer', 'supplier', 'both'];

    protected $fillable = [
        'user_id', 'team_id', 'type', 'name', 'legal_name', 'email', 'phone', 'tax_number', 'registration_number',
        'billing_address', 'shipping_address', 'currency', 'payment_terms_days', 'credit_limit_cents', 'status',
        'external_ref', 'notes',
    ];

    protected $casts = [
        'billing_address' => 'array',
        'shipping_address' => 'array',
        'payment_terms_days' => 'integer',
        'credit_limit_cents' => 'integer',
    ];

    protected static function booted(): void
    {
        // Tidy the identifiers that must compare equal: a VAT number typed with spaces is the same number.
        static::saving(function (self $party) {
            foreach (['tax_number', 'registration_number'] as $field) {
                $value = $party->{$field};
                $party->{$field} = $value === null ? null : (preg_replace('/\s+/', '', trim($value)) ?: null);
            }
            foreach (['email', 'phone', 'legal_name', 'external_ref', 'currency'] as $field) {
                $value = $party->{$field};
                $party->{$field} = $value === null ? null : (trim($value) ?: null);
            }
            if ($party->currency !== null) {
                $party->currency = strtoupper($party->currency);
            }
        });
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function isCustomer(): bool
    {
        return in_array($this->type, ['customer', 'both'], true);
    }

    public function isSupplier(): bool
    {
        return in_array($this->type, ['supplier', 'both'], true);
    }

    public function scopeCustomers(Builder $query): Builder
    {
        return $query->whereIn('type', ['customer', 'both']);
    }

    public function scopeSuppliers(Builder $query): Builder
    {
        return $query->whereIn('type', ['supplier', 'both']);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /** The name to show: the trading name, with the legal name when it differs. */
    public function displayName(): string
    {
        return $this->legal_name && $this->legal_name !== $this->name ? "{$this->name} ({$this->legal_name})" : $this->name;
    }
}
