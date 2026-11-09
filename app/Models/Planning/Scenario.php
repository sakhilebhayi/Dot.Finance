<?php

namespace App\Models\Planning;

use App\Models\Concerns\HasLedgerContext;
use Illuminate\Database\Eloquent\Model;

/**
 * A named what-if. It holds assumptions only; nothing about a scenario is ever posted, and running one reads the
 * ledger without writing to it (tests prove the journal tables are byte-identical afterwards).
 */
class Scenario extends Model
{
    use HasLedgerContext;

    protected $table = 'plan_scenarios';

    protected $fillable = ['user_id', 'team_id', 'name', 'horizon', 'assumptions', 'created_by'];

    protected function casts(): array
    {
        return ['assumptions' => 'array'];
    }

    public function auditLabel(): string
    {
        return 'Scenario';
    }
}
