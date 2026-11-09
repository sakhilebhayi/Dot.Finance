<?php

namespace App\Models\Purchases;

use App\Models\Accounting\GlAccount;
use App\Models\Concerns\HasLedgerContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a bill: what was bought, the account it is charged to, the tax code, and the amounts the server worked out. */
class BillLine extends Model
{
    use HasLedgerContext;

    protected $fillable = [
        'user_id', 'team_id', 'bill_id', 'line_no', 'description', 'gl_account_id', 'quantity_e4', 'unit_price_cents', 'discount_bp',
        'tax_code', 'tax_code_id', 'tax_rate_bp', 'dimension', 'net_cents', 'tax_cents', 'gross_cents', 'claimable_tax_cents', 'claim_reason',
    ];

    protected $casts = [
        'line_no' => 'integer',
        'quantity_e4' => 'integer',
        'unit_price_cents' => 'integer',
        'discount_bp' => 'integer',
        'tax_rate_bp' => 'integer',
        'net_cents' => 'integer',
        'tax_cents' => 'integer',
        'gross_cents' => 'integer',
        'claimable_tax_cents' => 'integer',
    ];

    public function ledgerReferences(): array
    {
        return ['bill_id' => Bill::class, 'gl_account_id' => GlAccount::class];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'gl_account_id');
    }

    /** The quantity as a decimal string with up to four places ("2.5"). */
    public function quantity(): string
    {
        return self::formatQuantity($this->quantity_e4);
    }

    public static function formatQuantity(int $e4): string
    {
        $whole = intdiv($e4, 10000);
        $fraction = rtrim(str_pad((string) ($e4 % 10000), 4, '0', STR_PAD_LEFT), '0');

        return $fraction === '' ? (string) $whole : "{$whole}.{$fraction}";
    }

    /** "2.5" -> 25000. At most four decimals, positive. */
    public static function parseQuantity(string $quantity): int
    {
        if (preg_match('/^(\d{1,8})(?:\.(\d{1,4}))?$/', trim($quantity), $m) !== 1) {
            throw new \InvalidArgumentException("Not a valid quantity: {$quantity}");
        }

        $value = ((int) $m[1]) * 10000 + (int) str_pad($m[2] ?? '', 4, '0');

        if ($value <= 0) {
            throw new \InvalidArgumentException('A quantity must be greater than zero.');
        }

        return $value;
    }
}
