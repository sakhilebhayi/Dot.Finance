<?php

namespace App\Models\Accounting;

use App\Models\Concerns\HasLedgerContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One account of the chart. Never deleted: deactivated once it has been used. */
class GlAccount extends Model
{
    use HasLedgerContext;

    public const CLASSES = ['asset', 'liability', 'equity', 'income', 'expense'];

    protected $fillable = [
        'user_id', 'team_id', 'code', 'name', 'class', 'subtype', 'normal_side', 'parent_id', 'currency', 'system_key',
        'is_active', 'is_postable', 'description',
    ];

    protected $casts = ['is_active' => 'boolean', 'is_postable' => 'boolean'];

    public static function normalSideFor(string $class): string
    {
        return in_array($class, ['asset', 'expense'], true) ? 'debit' : 'credit';
    }

    public function ledgerReferences(): array
    {
        return ['parent_id' => self::class];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function isSystem(): bool
    {
        return $this->system_key !== null;
    }

    public function isDebitNormal(): bool
    {
        return $this->normal_side === 'debit';
    }

    public function hasPostings(): bool
    {
        return JournalLine::query()->forContext($this->ledgerContext())->where('gl_account_id', $this->id)->exists();
    }

    public function label(): string
    {
        return $this->code.' '.$this->name;
    }
}
