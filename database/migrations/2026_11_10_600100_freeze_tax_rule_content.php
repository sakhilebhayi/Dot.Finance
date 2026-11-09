<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * QA hardening (D-1308): the rule book is append-only in the models, but a raw UPDATE could still change what a rule SAYS, which would
 * silently change every past explanation that names that version ("tax_rules#12"). The database now refuses to change the content of
 * a published rule, rate or tax code in place; a new value is a new row with a later valid_from. Still writable: valid_to (the models
 * check it only moves earlier), review bookkeeping, notes and source references, and the display fields of a tax code.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const FROZEN = [
        'tax_rules' => ['user_id', 'team_id', 'jurisdiction', 'tax_type', 'rule_key', 'qualifier', 'value_type', 'value', 'unit', 'valid_from', 'legal_status', 'source_url', 'accessed_at', 'confidence'],
        'tax_rates' => ['user_id', 'team_id', 'jurisdiction', 'rate_key', 'rate_bp', 'valid_from', 'legal_status', 'source_url', 'accessed_at', 'confidence'],
        'tax_codes' => ['user_id', 'team_id', 'jurisdiction', 'code', 'kind', 'applies_to', 'rate_ref', 'box_output', 'box_input', 'deductible_policy', 'valid_from'],
    ];

    /** the `value` column is json (not jsonb) on PostgreSQL, which has no equality operator: compare its text */
    private function expr(string $side, string $column): string
    {
        return $column === 'value' && DB::getDriverName() === 'pgsql' ? "{$side}.{$column}::text" : "{$side}.{$column}";
    }

    public function up(): void
    {
        foreach (self::FROZEN as $table => $columns) {
            $msg = "{$table}: a published version never changes in place; add a new version with a later valid_from";

            if (DB::getDriverName() === 'sqlite') {
                $same = collect($columns)->map(fn ($c) => "OLD.{$c} IS NEW.{$c}")->implode(' AND ');
                DB::unprepared("CREATE TRIGGER IF NOT EXISTS {$table}_content_frozen BEFORE UPDATE ON {$table} WHEN NOT ({$same}) BEGIN SELECT RAISE(ABORT, '{$msg}'); END");

                continue;
            }

            $distinct = collect($columns)->map(fn ($c) => $this->expr('OLD', $c).' IS DISTINCT FROM '.$this->expr('NEW', $c))->implode(' OR ');
            DB::unprepared(<<<SQL
CREATE OR REPLACE FUNCTION {$table}_content_frozen() RETURNS trigger AS \$\$
BEGIN
    IF {$distinct} THEN
        RAISE EXCEPTION '{$msg}' USING ERRCODE = 'integrity_constraint_violation';
    END IF;
    RETURN NEW;
END;
\$\$ LANGUAGE plpgsql
SQL);
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_content_frozen_trg ON {$table}");
            DB::unprepared("CREATE TRIGGER {$table}_content_frozen_trg BEFORE UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION {$table}_content_frozen()");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::FROZEN) as $table) {
            if (DB::getDriverName() === 'sqlite') {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_content_frozen");

                continue;
            }
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_content_frozen_trg ON {$table}");
            DB::unprepared("DROP FUNCTION IF EXISTS {$table}_content_frozen()");
        }
    }
};
