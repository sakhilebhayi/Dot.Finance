<?php

namespace App\Models\Bank;

use App\Models\Account;
use App\Models\Category;
use App\Models\Concerns\HasLedgerContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "If the narrative contains X then categorise it as Y." A rule only ever SUGGESTS: a statement line that a rule fits is
 * offered with the rule named and the person confirms. Nothing is created or categorised silently.
 */
class BankRule extends Model
{
    use HasLedgerContext;

    public const FIELDS = ['any' => 'Description or reference', 'description' => 'Description', 'reference' => 'Reference'];

    public const OPERATORS = ['contains' => 'contains', 'starts_with' => 'starts with', 'equals' => 'is exactly'];

    public const DIRECTIONS = ['any' => 'Money in or out', 'in' => 'Money in', 'out' => 'Money out'];

    protected $fillable = [
        'user_id', 'team_id', 'account_id', 'name', 'match_field', 'operator', 'pattern', 'direction', 'min_cents', 'max_cents',
        'category_id', 'party_id', 'new_description', 'priority', 'is_active', 'times_applied', 'last_applied_at',
    ];

    protected $casts = [
        'min_cents' => 'integer',
        'max_cents' => 'integer',
        'priority' => 'integer',
        'is_active' => 'boolean',
        'times_applied' => 'integer',
        'last_applied_at' => 'datetime',
    ];

    public function ledgerReferences(): array
    {
        return ['account_id' => Account::class, 'category_id' => Category::class];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withTrashed();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class)->withTrashed();
    }

    public function auditLabel(): string
    {
        return 'Bank rule '.$this->name;
    }

    /** The sentence the rule is, for the list and for the "matched because" text. */
    public function sentence(): string
    {
        $field = mb_strtolower(self::FIELDS[$this->match_field] ?? 'description');
        $op = self::OPERATORS[$this->operator] ?? 'contains';

        return "If the {$field} {$op} \"{$this->pattern}\"";
    }
}
