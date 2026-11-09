#!/usr/bin/env bash
#
# Dot.Finance restore drill: prove a backup set can be restored and that the books in it still tie out.
#
# It restores into a THROWAWAY database named restore_drill_<timestamp>, points a copy of the application at that database
# through the environment, runs the application's own integrity commands against it, compares the result with what the
# backup recorded, and drops the throwaway database. It never connects to the source database for writing, never restores
# over it, and refuses to run if the drill database name would equal the source database name.
#
#   1. restore         pg_restore -Fc into restore_drill_<ts>                      (timed)
#   2. row counts      every table, restored count == count recorded at backup time
#   3. ledger heads    newest entry number and hash per ledger == the recorded head
#   4. triggers        the append-only and balance triggers exist again, and an UPDATE of a posted line is REFUSED
#   5. app checks      finance:gl:verify (balanced, trial balance, cash tie-out, gap-free numbers + hash chain, periods, isolation),
#                      finance:check-balances, sales:check-receivables   -- run by the application against the restored database
#   6. APP_KEY         the restored encrypted columns decrypt with the key from the backup's env copy
#
# Usage:   scripts/ops/restore-drill.sh [BACKUP_SET_DIR]       (default: the newest set in BACKUP_DIR)
# Config:  as backup.sh (BACKUP_DIR, DB_*, PG_*), plus
#   KEEP_DRILL_DB=1        do not drop the drill database at the end (it is named in the output; drop it yourself)
#   BACKUP_PASSPHRASE_FILE needed when the set is encrypted
#   PHP                    php binary for the application checks (default php); the app is run with this folder's code
#   SKIP_APP_CHECKS=1      restore and compare only
# The database role needs CREATEDB (the drill creates and drops its own database). On hosts where it does not, create an empty
# database called restore_drill_<ts> in the hosting panel, then run with DRILL_DB=restore_drill_<ts> (it must match that pattern).
#
# Exit status 0 only when every step passed.

set -uo pipefail
umask 077

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
cd "$APP_DIR"

env_file_value() {
  local key="$1" line
  [ -f "$APP_DIR/.env" ] || return 0
  line="$(grep -E "^${key}=" "$APP_DIR/.env" | tail -n 1 || true)"
  line="${line#*=}"; line="${line%\"}"; line="${line#\"}"; line="${line%\'}"; line="${line#\'}"
  printf '%s' "$line"
}
setting() { local name="$1" default="${2:-}" value; value="${!name:-}"; [ -n "$value" ] || value="$(env_file_value "$name")"; [ -n "$value" ] || value="$default"; printf '%s' "$value"; }

DB_CONNECTION="$(setting DB_CONNECTION pgsql)"
DB_HOST="$(setting DB_HOST 127.0.0.1)"
DB_PORT="$(setting DB_PORT 5432)"
DB_DATABASE="$(setting DB_DATABASE)"
DB_USERNAME="$(setting DB_USERNAME "$(whoami)")"
DB_PASSWORD="$(setting DB_PASSWORD)"
BACKUP_DIR="$(setting BACKUP_DIR "$HOME/backups/dot-finance")"
PASSFILE="$(setting BACKUP_PASSPHRASE_FILE)"
PHP="${PHP:-php}"
PG_RESTORE="${PG_RESTORE:-pg_restore}"
PSQL="${PSQL:-psql}"
CREATEDB="${CREATEDB:-createdb}"
DROPDB="${DROPDB:-dropdb}"

PASS=0; FAIL=0
ok()   { printf '  [ok]   %s\n' "$*"; PASS=$((PASS + 1)); }
bad()  { printf '  [FAIL] %s\n' "$*"; FAIL=$((FAIL + 1)); }
step() { printf '\n== %s\n' "$*"; }
die()  { printf 'restore-drill: %s\n' "$*" >&2; exit 2; }

[ "$DB_CONNECTION" = "pgsql" ] || die "this drill is written for PostgreSQL (DB_CONNECTION=$DB_CONNECTION). For MySQL restore the .sql.gz into a scratch database by hand and run the app checks with DB_DATABASE pointed at it (docs/ops/backup-restore.md)."
[ -n "$DB_DATABASE" ] || die "DB_DATABASE is not set."

SET="${1:-}"
if [ -z "$SET" ]; then SET="$(find "$BACKUP_DIR" -maxdepth 1 -type d -name 'set-*' | sort | tail -n 1)"; fi
[ -n "$SET" ] && [ -d "$SET" ] || die "no backup set found (looked in $BACKUP_DIR)."

TS="$(date -u +%Y%m%d%H%M%S)"
DRILL_DB="${DRILL_DB:-restore_drill_$TS}"
[[ "$DRILL_DB" =~ ^restore_drill_[0-9]{8,14}$ ]] || die "the drill database must be named restore_drill_<digits> (got $DRILL_DB)."
[ "$DRILL_DB" != "$DB_DATABASE" ] || die "the drill database equals the source database. Refusing."

export PGPASSWORD="$DB_PASSWORD"
PGARGS=(-h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME")
WORK="$(mktemp -d "${TMPDIR:-/tmp}/restore-drill.XXXXXX")"
DB_CREATED=0
finish() {
  if [ "$DB_CREATED" = 1 ] && [ "${KEEP_DRILL_DB:-}" != "1" ]; then "$DROPDB" "${PGARGS[@]}" --if-exists "$DRILL_DB" >/dev/null 2>&1 && printf '\ndropped throwaway database %s\n' "$DRILL_DB"; fi
  [ "${KEEP_DRILL_DB:-}" = "1" ] && [ "$DB_CREATED" = 1 ] && printf '\nkept throwaway database %s (drop it: dropdb -h %s %s)\n' "$DRILL_DB" "$DB_HOST" "$DRILL_DB"
  rm -rf "$WORK"
}
trap finish EXIT

printf 'restore drill\n  backup set: %s\n  source db:  %s (read only: never touched)\n  drill db:   %s\n' "$SET" "$DB_DATABASE" "$DRILL_DB"
[ -f "$SET/MANIFEST.txt" ] && sed 's/^/  | /' "$SET/MANIFEST.txt"

# ---- 0. the set itself ----------------------------------------------------------------------------------------------------
step "0. the set is intact (SHA256SUMS)"
if ( cd "$SET" && if command -v sha256sum >/dev/null; then sha256sum -c SHA256SUMS >/dev/null 2>&1; else shasum -a 256 -c SHA256SUMS >/dev/null 2>&1; fi ); then ok "every file matches its checksum"; else bad "a file in the set does not match SHA256SUMS"; exit 1; fi

fetch() { # fetch NAME -> path of the readable (decrypted if need be) file in $WORK, or empty
  local name="$1"
  if [ -f "$SET/$name" ]; then printf '%s' "$SET/$name"; return; fi
  if [ -f "$SET/$name.enc" ]; then
    [ -n "$PASSFILE" ] || die "$name is encrypted: set BACKUP_PASSPHRASE_FILE."
    openssl enc -d -aes-256-cbc -pbkdf2 -pass "file:$PASSFILE" -in "$SET/$name.enc" -out "$WORK/$name" || die "cannot decrypt $name."
    printf '%s' "$WORK/$name"
  fi
}
DUMP="$(fetch database.dump)"; [ -n "$DUMP" ] || die "database.dump is not in the set."
COUNTS="$(fetch row-counts.tsv)"; HEADS="$(fetch ledger-heads.tsv)"; ENVCOPY="$(fetch env.backup)"

# ---- 1. restore -----------------------------------------------------------------------------------------------------------
step "1. restore into $DRILL_DB"
if [ -n "${DRILL_DB_PREMADE:-}" ]; then :; else "$CREATEDB" "${PGARGS[@]}" "$DRILL_DB" || die "cannot create $DRILL_DB (the role needs CREATEDB)."; fi
DB_CREATED=1
T0=$(date +%s)
"$PG_RESTORE" "${PGARGS[@]}" -d "$DRILL_DB" --no-owner --no-privileges --exit-on-error "$DUMP" 2> "$WORK/restore.err"
RC=$?
RESTORE_SECONDS=$(( $(date +%s) - T0 ))
if [ "$RC" = 0 ]; then ok "pg_restore finished in ${RESTORE_SECONDS}s"; else bad "pg_restore failed (exit $RC): $(head -3 "$WORK/restore.err" | tr '\n' ' ')"; exit 1; fi
q() { "$PSQL" "${PGARGS[@]}" -d "$DRILL_DB" -Atq "$@"; }

# A dump holds rows, not planner statistics: a restored database has none until ANALYZE runs, and until then PostgreSQL plans
# every query blind (measured: finance:gl:verify 23 s instead of 5 s, sales:check-receivables 70 s instead of under 1 s).
# This is the step to run on a real restore before the application is let back in.
T0=$(date +%s)
q -c "ANALYZE" >/dev/null 2>&1 && ok "ANALYZE finished in $(( $(date +%s) - T0 ))s (do this on a real restore before reopening the app)" || bad "ANALYZE failed"

# ---- 2. row counts --------------------------------------------------------------------------------------------------------
step "2. row counts equal the counts recorded at backup time"
if [ -n "$COUNTS" ] && [ -s "$COUNTS" ]; then
  MISMATCH=0; TABLES=0
  while IFS=$'\t' read -r t n; do
    TABLES=$((TABLES + 1))
    got="$(q -c "SELECT count(*) FROM \"$t\"" 2>/dev/null || echo MISSING)"
    if [ "$got" != "$n" ]; then bad "$t: backup $n, restored $got"; MISMATCH=$((MISMATCH + 1)); fi
  done < "$COUNTS"
  [ "$MISMATCH" = 0 ] && ok "$TABLES tables, all counts equal"
else
  printf '  [skip] the set holds no row counts\n'
fi

# ---- 3. ledger heads (hash chain heads) -----------------------------------------------------------------------------------
step "3. each ledger's newest entry and hash equal the recorded head"
if [ -n "$HEADS" ] && [ -s "$HEADS" ]; then
  HEADBAD=0; LEDGERS=0
  while IFS=$'\t' read -r ledger number hash entries; do
    LEDGERS=$((LEDGERS + 1))
    kind="${ledger%%:*}"; id="${ledger#*:}"
    col="team_id"; [ "$kind" = "user" ] && col="user_id"
    got="$(q -F $'\t' -c "SELECT entry_number, entry_hash, (SELECT count(*) FROM journal_entries WHERE $col = $id) FROM journal_entries WHERE $col = $id ORDER BY entry_number DESC LIMIT 1")"
    if [ "$got" != "$number"$'\t'"$hash"$'\t'"$entries" ]; then bad "$ledger: head differs (backup #$number $entries entries; restored ${got%%$'\t'*})"; HEADBAD=$((HEADBAD + 1)); fi
  done < "$HEADS"
  [ "$HEADBAD" = 0 ] && ok "$LEDGERS ledger(s): head entry number, hash and entry count equal"
fi

# ---- 4. triggers ----------------------------------------------------------------------------------------------------------
step "4. the append-only guarantees survived the restore"
TRIG="$(q -c "SELECT string_agg(tgname, ',' ORDER BY tgname) FROM pg_trigger WHERE NOT tgisinternal AND tgname IN ('journal_lines_immutable','journal_entries_immutable','journal_lines_balance','journal_entries_balance')")"
EXPECT="journal_entries_balance,journal_entries_immutable,journal_lines_balance,journal_lines_immutable"
if [ "$TRIG" = "$EXPECT" ]; then ok "the four journal triggers exist (immutable x2, must-balance x2)"; else bad "journal triggers missing or different: '$TRIG'"; fi
PROBE="$(q -c "BEGIN; UPDATE journal_lines SET debit_cents = debit_cents + 1 WHERE id = (SELECT MIN(id) FROM journal_lines); ROLLBACK;" 2>&1 | head -1)"
if printf '%s' "$PROBE" | grep -qi "ERROR"; then ok "UPDATE of a posted journal line is refused: ${PROBE:0:90}"; else bad "an UPDATE of a posted journal line was NOT refused ($PROBE)"; fi
PROBE2="$(q -c "BEGIN; DELETE FROM journal_entries WHERE id = (SELECT MIN(id) FROM journal_entries); ROLLBACK;" 2>&1 | head -1)"
if printf '%s' "$PROBE2" | grep -qi "ERROR"; then ok "DELETE of a posted journal entry is refused"; else bad "a DELETE of a posted journal entry was NOT refused ($PROBE2)"; fi

# ---- 5. the application's own checks, against the restored database ---------------------------------------------------------
if [ "${SKIP_APP_CHECKS:-}" = "1" ]; then
  step "5. application checks skipped (SKIP_APP_CHECKS=1)"
else
  step "5. application checks run against $DRILL_DB"
  # A cached config would silently win over these variables and point the checks at the LIVE database. Point the app at a cache
  # path that does not exist so the config is built from .env + the variables below, then prove which database it will use.
  APP_ENV_VARS=(APP_CONFIG_CACHE="$WORK/no-config-cache.php" DB_CONNECTION=pgsql DB_HOST="$DB_HOST" DB_PORT="$DB_PORT" DB_DATABASE="$DRILL_DB" DB_USERNAME="$DB_USERNAME" DB_PASSWORD="$DB_PASSWORD" CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array)
  if [ -n "$ENVCOPY" ] && grep -q '^APP_KEY=' "$ENVCOPY"; then APP_ENV_VARS+=(APP_KEY="$(grep '^APP_KEY=' "$ENVCOPY" | tail -n1 | cut -d= -f2-)"); fi
  run_app() { env "${APP_ENV_VARS[@]}" "$PHP" -d memory_limit="${APP_MEMORY_LIMIT:-512M}" "$APP_DIR/artisan" "$@"; }
  USING="$(run_app finance:ops:restore-check --expect-database="$DRILL_DB" 2>&1 | sed -n 's/^database: //p' | head -n 1 | tr -d '[:space:]')"
  if [ "$USING" != "$DRILL_DB" ]; then
    bad "the application would use database '${USING:-unknown}', not $DRILL_DB. Refusing to run the checks."
  else
    ok "the application is pointed at $DRILL_DB (not at $DB_DATABASE)"
    T1=$(date +%s)
    OUT="$(run_app finance:gl:verify 2>&1)"; RC=$?
    GLV_SECONDS=$(( $(date +%s) - T1 ))
    if [ "$RC" = 0 ]; then ok "finance:gl:verify passed in ${GLV_SECONDS}s ($(printf '%s\n' "$OUT" | grep -c '\[ok\]') checks ok)"; else bad "finance:gl:verify FAILED"; printf '%s\n' "$OUT" | grep -v '\[ok\]' | head -12 | sed 's/^/         /'; fi
    T1=$(date +%s)
    OUT="$(run_app finance:check-balances 2>&1)"; RC=$?
    if [ "$RC" = 0 ]; then ok "finance:check-balances passed in $(( $(date +%s) - T1 ))s ($(printf '%s\n' "$OUT" | tail -n 1))"; else bad "finance:check-balances FAILED"; printf '%s\n' "$OUT" | tail -8 | sed 's/^/         /'; fi
    if run_app list --raw 2>/dev/null | grep -q '^sales:check-receivables'; then
      T1=$(date +%s)
      OUT="$(run_app sales:check-receivables 2>&1)"; RC=$?
      if [ "$RC" = 0 ]; then ok "sales:check-receivables passed in $(( $(date +%s) - T1 ))s"; else bad "sales:check-receivables FAILED"; printf '%s\n' "$OUT" | tail -6 | sed 's/^/         /'; fi
    fi

    # ---- 6. APP_KEY: an encrypted column decrypts with the key that came out of the backup ---------------------------------
    step "6. encrypted columns decrypt with the APP_KEY from the backup"
    OUT="$(run_app finance:ops:restore-check --expect-database="$DRILL_DB" 2>&1)"; RC=$?
    if [ "$RC" = 0 ]; then ok "every encrypted column that has rows decrypts: $(printf '%s\n' "$OUT" | grep '^encrypted' | sed 's/encrypted //' | tr '\n' ';')"; else bad "an encrypted column did NOT decrypt. Is APP_KEY in env.backup the key the data was written with?"; printf '%s\n' "$OUT" | sed 's/^/         /'; fi
  fi
fi

step "result"
printf '  restore %ss, %s passed, %s failed\n' "${RESTORE_SECONDS:-?}" "$PASS" "$FAIL"
[ "$FAIL" = 0 ]
