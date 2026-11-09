<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * QA hardening of the tax return freeze (R-44 tax QA, D-1301..D-1306).
 *
 * The first freeze triggers (2026_11_09_400000) protected the headline columns of a submitted return but NOT the JSON documents the
 * working paper is actually printed from (figures, rule_versions, reconciliation, checks), nor the filing channel, the period label,
 * the preparer, the ledger owner or the payment record. A raw UPDATE of `figures` on a submitted return therefore changed the numbers
 * on the printed working paper while `figures_hash` still looked intact. This migration replaces the triggers (both engines) so that:
 *
 *   - a PREPARED return cannot change any of its frozen content while it stays prepared (withdraw first);
 *   - a SUBMITTED or PAID return cannot change figures, rule versions, reconciliation, checks, channel, label, preparer, owner, reference,
 *     date, period, status history (as before);
 *   - once PAID, the payment record (amount, date, reference, person, proof) is frozen too;
 *   - the settlement journal pointer can be set once (NULL to a value) and never changed or cleared;
 *   - the export counter and last-exported stamp stay writable (an export is not a change to the return).
 */
return new class extends Migration
{
    /** @var list<string> */
    private const HEAD = ['kind', 'period_start', 'period_end', 'revision', 'basis', 'figures_hash', 'output_tax_cents', 'input_tax_cents', 'payable_cents', 'sars_reference', 'submitted_on', 'submitted_by_user_id', 'prepared_at'];

    /** @var list<string> */
    private const ADDED = ['user_id', 'team_id', 'period_label', 'channel', 'rule_versions', 'figures', 'reconciliation', 'checks', 'prepared_by_user_id', 'supersedes_return_id'];

    /** @var list<string> */
    private const PAYMENT = ['paid_cents', 'paid_on', 'payment_reference', 'paid_by_user_id', 'payment_proof_attachment_id'];

    /** @var list<string> */
    private const PREPARED = ['kind', 'period_start', 'period_end', 'revision', 'basis', 'channel', 'period_label', 'figures_hash', 'figures', 'rule_versions', 'reconciliation', 'checks', 'output_tax_cents', 'input_tax_cents', 'payable_cents', 'prepared_by_user_id', 'prepared_at', 'user_id', 'team_id'];

    private const MSG_FROZEN = 'a return recorded as submitted is frozen: its figures, period and SARS reference never change (record a revision instead)';

    private const MSG_PREPARED = 'a prepared return has frozen figures: withdraw the preparation to change them';

    private const MSG_PAID = 'a recorded payment never changes';

    private const MSG_SETTLEMENT = 'the settlement journal of a return is set once and never changed';

    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (['tax_returns_frozen_update', 'tax_returns_prepared_figures', 'tax_returns_paid_record', 'tax_returns_settlement_once'] as $t) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$t}");
            }
            $same = fn (array $cols) => collect($cols)->map(fn ($c) => "OLD.{$c} IS NEW.{$c}")->implode(' AND ');
            $msg = self::MSG_FROZEN;

            DB::unprepared("CREATE TRIGGER tax_returns_frozen_update BEFORE UPDATE ON tax_returns WHEN OLD.status IN ('submitted','paid') AND (NOT ({$same(array_merge(self::HEAD, self::ADDED))}) OR NEW.status NOT IN ('submitted','paid') OR (OLD.status = 'paid' AND NEW.status <> 'paid')) BEGIN SELECT RAISE(ABORT, '{$msg}'); END");
            DB::unprepared("CREATE TRIGGER tax_returns_prepared_figures BEFORE UPDATE ON tax_returns WHEN OLD.status = 'prepared' AND NEW.status = 'prepared' AND NOT ({$same(self::PREPARED)}) BEGIN SELECT RAISE(ABORT, '".self::MSG_PREPARED."'); END");
            DB::unprepared("CREATE TRIGGER tax_returns_paid_record BEFORE UPDATE ON tax_returns WHEN OLD.status = 'paid' AND NOT ({$same(self::PAYMENT)}) BEGIN SELECT RAISE(ABORT, '".self::MSG_PAID."'); END");
            DB::unprepared("CREATE TRIGGER tax_returns_settlement_once BEFORE UPDATE ON tax_returns WHEN OLD.settlement_entry_id IS NOT NULL AND NOT (OLD.settlement_entry_id IS NEW.settlement_entry_id) BEGIN SELECT RAISE(ABORT, '".self::MSG_SETTLEMENT."'); END");

            return;
        }

        $distinct = fn (array $cols) => collect($cols)->map(fn ($c) => "OLD.{$c} IS DISTINCT FROM NEW.{$c}")->implode(' OR ');
        $frozen = $distinct(array_merge(self::HEAD, self::ADDED));
        $prepared = $distinct(self::PREPARED);
        $payment = $distinct(self::PAYMENT);

        DB::unprepared(<<<SQL
CREATE OR REPLACE FUNCTION tax_returns_frozen() RETURNS trigger AS \$\$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.status <> 'draft' THEN
            RAISE EXCEPTION 'only a draft return can be deleted' USING ERRCODE = 'integrity_constraint_violation';
        END IF;
        RETURN OLD;
    END IF;
    IF OLD.status IN ('submitted','paid') AND ({$frozen} OR NEW.status NOT IN ('submitted','paid') OR (OLD.status = 'paid' AND NEW.status <> 'paid')) THEN
        RAISE EXCEPTION '{$this->q(self::MSG_FROZEN)}' USING ERRCODE = 'integrity_constraint_violation';
    END IF;
    IF OLD.status = 'prepared' AND NEW.status = 'prepared' AND ({$prepared}) THEN
        RAISE EXCEPTION '{$this->q(self::MSG_PREPARED)}' USING ERRCODE = 'integrity_constraint_violation';
    END IF;
    IF OLD.status = 'paid' AND ({$payment}) THEN
        RAISE EXCEPTION '{$this->q(self::MSG_PAID)}' USING ERRCODE = 'integrity_constraint_violation';
    END IF;
    IF OLD.settlement_entry_id IS NOT NULL AND OLD.settlement_entry_id IS DISTINCT FROM NEW.settlement_entry_id THEN
        RAISE EXCEPTION '{$this->q(self::MSG_SETTLEMENT)}' USING ERRCODE = 'integrity_constraint_violation';
    END IF;
    RETURN NEW;
END;
\$\$ LANGUAGE plpgsql
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (['tax_returns_frozen_update', 'tax_returns_prepared_figures', 'tax_returns_paid_record', 'tax_returns_settlement_once'] as $t) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$t}");
            }
            $same = collect(self::HEAD)->map(fn ($c) => "OLD.{$c} IS NEW.{$c}")->implode(' AND ');
            DB::unprepared("CREATE TRIGGER tax_returns_frozen_update BEFORE UPDATE ON tax_returns WHEN OLD.status IN ('submitted','paid') AND (NOT ({$same}) OR NEW.status NOT IN ('submitted','paid') OR (OLD.status = 'paid' AND NEW.status <> 'paid')) BEGIN SELECT RAISE(ABORT, '".self::MSG_FROZEN."'); END");
            DB::unprepared("CREATE TRIGGER tax_returns_prepared_figures BEFORE UPDATE ON tax_returns WHEN OLD.status = 'prepared' AND NEW.status = 'prepared' AND NOT (OLD.figures_hash IS NEW.figures_hash) BEGIN SELECT RAISE(ABORT, '".self::MSG_PREPARED."'); END");

            return;
        }

        $distinct = collect(self::HEAD)->map(fn ($c) => "OLD.{$c} IS DISTINCT FROM NEW.{$c}")->implode(' OR ');
        DB::unprepared(<<<SQL
CREATE OR REPLACE FUNCTION tax_returns_frozen() RETURNS trigger AS \$\$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.status <> 'draft' THEN
            RAISE EXCEPTION 'only a draft return can be deleted' USING ERRCODE = 'integrity_constraint_violation';
        END IF;
        RETURN OLD;
    END IF;
    IF OLD.status IN ('submitted','paid') AND ({$distinct} OR NEW.status NOT IN ('submitted','paid') OR (OLD.status = 'paid' AND NEW.status <> 'paid')) THEN
        RAISE EXCEPTION '{$this->q(self::MSG_FROZEN)}' USING ERRCODE = 'integrity_constraint_violation';
    END IF;
    IF OLD.status = 'prepared' AND NEW.status = 'prepared' AND OLD.figures_hash IS DISTINCT FROM NEW.figures_hash THEN
        RAISE EXCEPTION '{$this->q(self::MSG_PREPARED)}' USING ERRCODE = 'integrity_constraint_violation';
    END IF;
    RETURN NEW;
END;
\$\$ LANGUAGE plpgsql
SQL);
    }

    private function q(string $text): string
    {
        return str_replace("'", "''", $text);
    }
};
