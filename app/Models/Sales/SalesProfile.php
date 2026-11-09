<?php

namespace App\Models\Sales;

use App\Models\Concerns\HasLedgerContext;
use Illuminate\Database\Eloquent\Model;

/**
 * The business as it appears on a document: names, tax registration, address, banking details, logo and brand
 * colour, defaults. One row per ledger. Whether the business is VAT-registered is decided here until the Tax
 * module's financial profile takes over (VatStatus is the seam).
 */
class SalesProfile extends Model
{
    use HasLedgerContext;

    protected $guarded = [];

    protected $casts = [
        'vat_registered' => 'boolean',
        'address' => 'array',
        'bank_account_number' => 'encrypted',
        'prices_include_tax' => 'boolean',
        'reminders_enabled' => 'boolean',
        'reminder_after_days' => 'array',
        'default_terms_days' => 'integer',
        'quote_validity_days' => 'integer',
        'reminder_before_days' => 'integer',
    ];

    public function displayName(): ?string
    {
        return $this->trading_name ?: $this->legal_name;
    }

    /** The address as lines, empty parts dropped. @return list<string> */
    public function addressLines(): array
    {
        $a = $this->address ?? [];

        return array_values(array_filter([$a['line1'] ?? null, $a['line2'] ?? null, trim(($a['suburb'] ?? '').' '.($a['city'] ?? '')), trim(($a['province'] ?? '').' '.($a['postal_code'] ?? '')), $a['country'] ?? null], fn ($l) => $l !== null && trim($l) !== ''));
    }

    public function hasAddress(): bool
    {
        return $this->addressLines() !== [];
    }

    /** A masked account number for screens: only the last four characters. */
    public function maskedAccount(): ?string
    {
        $n = $this->bank_account_number;

        return $n === null || $n === '' ? null : str_repeat('•', max(0, strlen($n) - 4)).substr($n, -4);
    }

    /** @return list<int> */
    public function reminderAfterDays(): array
    {
        return array_values(array_filter(array_map('intval', $this->reminder_after_days ?? [7, 14, 30]), fn ($d) => $d > 0));
    }
}
