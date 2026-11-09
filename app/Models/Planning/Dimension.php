<?php

namespace App\Models\Planning;

use App\Models\Concerns\HasLedgerContext;
use Illuminate\Database\Eloquent\Model;

/**
 * A named department or project. Journal lines carry the string in `dimension` ("department:3", "project:12");
 * this row gives the string a name for reports and budgets. A dimension used on a line but never named here
 * still reports, under its raw reference.
 */
class Dimension extends Model
{
    use HasLedgerContext;

    public const KINDS = ['department' => 'Department', 'project' => 'Project', 'other' => 'Other'];

    protected $fillable = ['user_id', 'team_id', 'ref', 'kind', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function auditLabel(): string
    {
        return 'Dimension '.$this->ref;
    }
}
