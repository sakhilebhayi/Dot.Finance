<?php

namespace App\Models\Sales;

use App\Models\Accounting\GlAccount;
use App\Models\Concerns\HasLedgerContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** One line of a sales document. Written only by DocumentDrafts while the document is a draft; frozen with it. */
class SalesDocumentLine extends Model
{
    use HasLedgerContext;

    protected $guarded = [];

    protected $casts = [
        'quantity_e4' => 'integer',
        'unit_cents' => 'integer',
        'discount_bp' => 'integer',
        'discount_cents' => 'integer',
        'rate_bp' => 'integer',
        'before_discount_cents' => 'integer',
        'line_discount_cents' => 'integer',
        'net_cents' => 'integer',
        'tax_cents' => 'integer',
        'gross_cents' => 'integer',
    ];

    protected static function booted(): void
    {
        $guard = function (self $line) {
            $sealed = SalesDocument::query()->forContext($line->ledgerContext())->whereKey($line->document_id)->whereNotNull('sealed_at')->exists();
            if ($sealed) {
                throw new LogicException('The lines of an issued document cannot be changed. Void it, or issue a credit note.');
            }
        };

        static::saving($guard);
        static::deleting($guard);
    }

    public function ledgerReferences(): array
    {
        return ['document_id' => SalesDocument::class, 'income_account_id' => GlAccount::class];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class, 'document_id');
    }

    /** The quantity as a decimal string with no trailing zeros ("2.5", "3"). */
    public function quantity(): string
    {
        return self::formatQuantity((int) $this->quantity_e4);
    }

    public static function formatQuantity(int $e4): string
    {
        $whole = intdiv($e4, 10_000);
        $fraction = rtrim(str_pad((string) ($e4 % 10_000), 4, '0', STR_PAD_LEFT), '0');

        return $fraction === '' ? (string) $whole : "{$whole}.{$fraction}";
    }

    /** "2.5" -> 25000. At most four decimals; anything else is refused. */
    public static function parseQuantity(string $quantity): int
    {
        if (preg_match('/^(\d{1,9})(?:\.(\d{1,4}))?$/', trim($quantity), $m) !== 1) {
            throw new \InvalidArgumentException("Not a valid quantity: {$quantity}");
        }

        return ((int) $m[1]) * 10_000 + (int) str_pad($m[2] ?? '', 4, '0');
    }
}
