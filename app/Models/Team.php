<?php

namespace App\Models;

use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Events\TeamCreated;
use Laravel\Jetstream\Events\TeamDeleted;
use Laravel\Jetstream\Events\TeamUpdated;
use Laravel\Jetstream\Team as JetstreamTeam;

class Team extends JetstreamTeam
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'personal_team',
    ];

    /**
     * A company, as far as the ledger is concerned: a non-personal team that InfoDot knows
     * (it has an InfoDot uuid). Only a company can own a company ledger; a personal team or
     * a hand-made team never can.
     */
    public function isCompany(): bool
    {
        return ! $this->personal_team && filled($this->infodot_team_uuid);
    }

    /** Tables whose rows can belong to a company ledger. */
    public const LEDGER_TABLES = ['accounts', 'categories', 'budgets', 'transactions', 'recurring_rules', 'finance_audit_log', 'ai_insights'];

    /**
     * Whether this company has any ledger rows here. A company's books are never deleted along
     * with a team or a person (the database refuses it too: the team_id keys restrict deletes).
     */
    public function hasLedgerData(): bool
    {
        foreach (self::LEDGER_TABLES as $table) {
            if (DB::table($table)->where('team_id', $this->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The event map for the model.
     *
     * @var array<string, class-string>
     */
    protected $dispatchesEvents = [
        'created' => TeamCreated::class,
        'updated' => TeamUpdated::class,
        'deleted' => TeamDeleted::class,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'personal_team' => 'boolean',
        ];
    }
}
