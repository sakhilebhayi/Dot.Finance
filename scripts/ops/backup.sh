#!/usr/bin/env bash
#
# Dot.Finance backup: one dated "backup set" holding everything a restore needs.
#
#   database.dump      pg_dump -Fc of the application database (or mysqldump / sqlite copy, see DB_CONNECTION)
#   storage.tar.gz     storage/app: private attachments, tax documents, report exports, logos, receipts
#   env.backup         a copy of .env (APP_KEY, DB and mail secrets). Encrypted when BACKUP_PASSPHRASE_FILE is set
#   row-counts.tsv     exact row count of every table at dump time (the restore drill compares against it)
#   ledger-heads.tsv   per ledger: newest journal entry number, its hash, and the entry count (the hash-chain head)
#   MANIFEST.txt       what, when, where from, how big, which code version
#   SHA256SUMS         checksum of every file above
#
# It only READS the database. Nothing here writes to, drops or alters the source database.
#
# Usage:   scripts/ops/backup.sh
# Config (environment, or the app's .env; a variable already set in the environment wins):
#   BACKUP_DIR                 where sets are kept (default $HOME/backups/dot-finance). Keep it OUTSIDE every web root.
#   BACKUP_KEEP_DAYS           daily sets kept this many days (default 14)
#   BACKUP_KEEP_MONTHLY        sets made on the 1st of a month are kept this many months (default 12)
#   BACKUP_PASSPHRASE_FILE     file holding a passphrase: every file in the set is then encrypted with openssl (AES-256, PBKDF2)
#   BACKUP_SKIP_STORAGE=1      database and .env only
#   PG_DUMP / PSQL / MYSQLDUMP full paths when the tools are not on PATH (typical on cPanel)
#   APP_DIR                    the Laravel folder (default: two levels above this script)
#
# Exit status 0 only when the set was written, checksummed and the dump was read back (pg_restore --list / gzip -t).

set -euo pipefail
umask 077

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
cd "$APP_DIR"

# ---- configuration: environment first, then .env -------------------------------------------------------------------
env_file_value() {
  local key="$1" line
  [ -f "$APP_DIR/.env" ] || return 0
  line="$(grep -E "^${key}=" "$APP_DIR/.env" | tail -n 1 || true)"
  line="${line#*=}"
  line="${line%\"}"; line="${line#\"}"; line="${line%\'}"; line="${line#\'}"
  printf '%s' "$line"
}
setting() { # setting NAME [default]
  local name="$1" default="${2:-}" value
  value="${!name:-}"
  [ -n "$value" ] || value="$(env_file_value "$name")"
  [ -n "$value" ] || value="$default"
  printf '%s' "$value"
}

DB_CONNECTION="$(setting DB_CONNECTION pgsql)"
DB_HOST="$(setting DB_HOST 127.0.0.1)"
DB_PORT="$(setting DB_PORT 5432)"
DB_DATABASE="$(setting DB_DATABASE)"
DB_USERNAME="$(setting DB_USERNAME)"
DB_PASSWORD="$(setting DB_PASSWORD)"
BACKUP_DIR="$(setting BACKUP_DIR "$HOME/backups/dot-finance")"
KEEP_DAYS="$(setting BACKUP_KEEP_DAYS 14)"
KEEP_MONTHS="$(setting BACKUP_KEEP_MONTHLY 12)"
PASSFILE="$(setting BACKUP_PASSPHRASE_FILE)"
PG_DUMP="${PG_DUMP:-pg_dump}"
PSQL="${PSQL:-psql}"
MYSQLDUMP="${MYSQLDUMP:-mysqldump}"
MYSQL="${MYSQL:-mysql}"

log() { printf '%s backup: %s\n' "$(date -u +%H:%M:%S)" "$*" >&2; }
die() { log "FAILED: $*"; exit 1; }

[ -n "$DB_DATABASE" ] || die "DB_DATABASE is not set (environment or $APP_DIR/.env)."
if [ -n "$PASSFILE" ]; then
  [ -r "$PASSFILE" ] || die "BACKUP_PASSPHRASE_FILE $PASSFILE is not readable."
  command -v openssl >/dev/null || die "openssl is needed for BACKUP_PASSPHRASE_FILE."
fi

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

# one backup at a time: a second run while the first is still going would race on the same minute and the same disk
LOCK="$BACKUP_DIR/.lock"
if command -v flock >/dev/null; then
  exec 9>"$LOCK"
  flock -n 9 || die "another backup is running (lock $LOCK)."
else
  mkdir "$LOCK.d" 2>/dev/null || die "another backup is running (lock $LOCK.d; remove it if no backup is running)."
  HAVE_DIR_LOCK=1
fi

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
SET="$BACKUP_DIR/set-$STAMP"
PART="$BACKUP_DIR/.partial-$STAMP"
mkdir "$PART"
# a failed or interrupted run leaves no half-written set behind, and releases the lock
on_exit() { [ -d "$PART" ] && rm -rf "$PART"; [ "${HAVE_DIR_LOCK:-}" = 1 ] && rmdir "$LOCK.d" 2>/dev/null; return 0; }
trap on_exit EXIT

START_EPOCH="$(date +%s)"
log "database $DB_CONNECTION://$DB_HOST/$DB_DATABASE -> $SET"

# ---- the database ----------------------------------------------------------------------------------------------------
case "$DB_CONNECTION" in
  pgsql)
    command -v "$PG_DUMP" >/dev/null || die "pg_dump not found (set PG_DUMP=/full/path/pg_dump)."
    export PGPASSWORD="$DB_PASSWORD"
    PGARGS=(-h "$DB_HOST" -p "$DB_PORT" -U "${DB_USERNAME:-$(whoami)}")
    # custom format: compressed, restorable table by table, and pg_restore --list can read it back to prove it is whole
    "$PG_DUMP" "${PGARGS[@]}" -Fc --no-owner --no-privileges -f "$PART/database.dump" "$DB_DATABASE"
    "${PG_DUMP/pg_dump/pg_restore}" --list "$PART/database.dump" >/dev/null 2>&1 || pg_restore --list "$PART/database.dump" >/dev/null || die "the dump cannot be read back."
    DUMP_FORMAT="pg_dump -Fc"
    # exact row counts of every table, and the head of each ledger's hash chain
    {
      "$PSQL" "${PGARGS[@]}" -d "$DB_DATABASE" -Atq -F $'\t' -c "SELECT table_name FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE' ORDER BY 1" |
      while IFS= read -r t; do
        n="$("$PSQL" "${PGARGS[@]}" -d "$DB_DATABASE" -Atq -c "SELECT count(*) FROM \"$t\"")"
        printf '%s\t%s\n' "$t" "$n"
      done
    } > "$PART/row-counts.tsv"
    "$PSQL" "${PGARGS[@]}" -d "$DB_DATABASE" -Atq -F $'\t' -c "
      SELECT COALESCE('team:'||e.team_id::text, 'user:'||e.user_id::text) AS ledger, e.entry_number, e.entry_hash, c.entries
      FROM journal_entries e
      JOIN (SELECT team_id, user_id, MAX(entry_number) AS n, COUNT(*) AS entries FROM journal_entries GROUP BY team_id, user_id) c
        ON c.n = e.entry_number AND c.team_id IS NOT DISTINCT FROM e.team_id AND c.user_id IS NOT DISTINCT FROM e.user_id
      ORDER BY 1" > "$PART/ledger-heads.tsv" 2>/dev/null || : > "$PART/ledger-heads.tsv"
    ;;
  mysql)
    command -v "$MYSQLDUMP" >/dev/null || die "mysqldump not found (set MYSQLDUMP=/full/path/mysqldump)."
    MYCNF="$PART/.my.cnf"
    printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword="%s"\n' "$DB_HOST" "$DB_PORT" "$DB_USERNAME" "$DB_PASSWORD" > "$MYCNF"
    "$MYSQLDUMP" --defaults-extra-file="$MYCNF" --single-transaction --quick --routines --triggers "$DB_DATABASE" | gzip -9 > "$PART/database.sql.gz"
    gzip -t "$PART/database.sql.gz" || die "the dump cannot be read back."
    DUMP_FORMAT="mysqldump | gzip"
    {
      "$MYSQL" --defaults-extra-file="$MYCNF" -N -B -e "SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' ORDER BY 1" "$DB_DATABASE" |
      while IFS= read -r t; do
        printf '%s\t%s\n' "$t" "$("$MYSQL" --defaults-extra-file="$MYCNF" -N -B -e "SELECT COUNT(*) FROM \`$t\`" "$DB_DATABASE")"
      done
    } > "$PART/row-counts.tsv"
    : > "$PART/ledger-heads.tsv"
    rm -f "$MYCNF"
    ;;
  sqlite)
    SRC="$DB_DATABASE"; [ -f "$SRC" ] || SRC="$APP_DIR/database/database.sqlite"
    [ -f "$SRC" ] || die "SQLite file not found ($DB_DATABASE)."
    if command -v sqlite3 >/dev/null; then sqlite3 "$SRC" ".backup '$PART/database.sqlite'"; else cp "$SRC" "$PART/database.sqlite"; fi
    DUMP_FORMAT="sqlite file copy"
    : > "$PART/row-counts.tsv"; : > "$PART/ledger-heads.tsv"
    ;;
  *) die "DB_CONNECTION=$DB_CONNECTION is not supported by this script." ;;
esac

# ---- private storage -------------------------------------------------------------------------------------------------
STORAGE_NOTE="skipped (BACKUP_SKIP_STORAGE=1)"
if [ "${BACKUP_SKIP_STORAGE:-}" != "1" ]; then
  if [ -d "$APP_DIR/storage/app" ]; then
    # report exports are rebuilt on request and are short-lived; everything else under storage/app is the only copy
    tar -czf "$PART/storage.tar.gz" -C "$APP_DIR/storage" --exclude='app/private/report-exports' --exclude='.gitignore' app
    tar -tzf "$PART/storage.tar.gz" >/dev/null || die "the storage archive cannot be read back."
    STORAGE_NOTE="$(tar -tzf "$PART/storage.tar.gz" | grep -vc '/$' || true) file(s)"
  else
    STORAGE_NOTE="no storage/app folder"
  fi
fi

# ---- the keys --------------------------------------------------------------------------------------------------------
if [ -f "$APP_DIR/.env" ]; then
  cp "$APP_DIR/.env" "$PART/env.backup"
  APP_KEY_PRESENT="$(grep -c '^APP_KEY=base64:' "$APP_DIR/.env" || true)"
else
  APP_KEY_PRESENT=0
  log "WARNING: no .env found; the set holds no keys. Without APP_KEY encrypted bank details and payroll lines cannot be read."
fi
[ "$APP_KEY_PRESENT" = "1" ] || log "WARNING: APP_KEY is not in $APP_DIR/.env as base64: ... keep the key somewhere else too."

# ---- manifest --------------------------------------------------------------------------------------------------------
GIT_REV="unknown"
if command -v git >/dev/null && git -C "$APP_DIR" rev-parse --short HEAD >/dev/null 2>&1; then GIT_REV="$(git -C "$APP_DIR" rev-parse --short HEAD)"; fi
{
  echo "Dot.Finance backup set"
  echo "created_utc:   $STAMP"
  echo "host:          $(hostname)"
  echo "code_version:  $GIT_REV"
  echo "database:      $DB_CONNECTION $DB_DATABASE @ $DB_HOST"
  echo "dump_format:   $DUMP_FORMAT"
  echo "storage:       $STORAGE_NOTE"
  echo "app_key_in_env_backup: $([ "$APP_KEY_PRESENT" = "1" ] && echo yes || echo NO)"
  echo "encrypted:     $([ -n "$PASSFILE" ] && echo 'yes (openssl aes-256-cbc pbkdf2; file names end .enc)' || echo 'no (protect this folder; the set holds personal data and your keys)')"
  echo "tables:        $(wc -l < "$PART/row-counts.tsv" | tr -d ' ')"
  echo "seconds:       $(( $(date +%s) - START_EPOCH ))"
} > "$PART/MANIFEST.txt"

# ---- optional encryption, then checksums -------------------------------------------------------------------------------
if [ -n "$PASSFILE" ]; then
  for f in database.dump database.sql.gz database.sqlite storage.tar.gz env.backup row-counts.tsv ledger-heads.tsv; do
    [ -f "$PART/$f" ] || continue
    openssl enc -aes-256-cbc -pbkdf2 -salt -pass "file:$PASSFILE" -in "$PART/$f" -out "$PART/$f.enc"
    rm -f "$PART/$f"
  done
fi
( cd "$PART" && if command -v sha256sum >/dev/null; then sha256sum -- * > SHA256SUMS; else shasum -a 256 -- * > SHA256SUMS; fi )

mv "$PART" "$SET"
log "set written: $SET ($(du -sh "$SET" | cut -f1), $(( $(date +%s) - START_EPOCH )) s)"

# ---- retention: daily sets for KEEP_DAYS, first-of-month sets for KEEP_MONTHLY months; the newest three are never removed ----
now_epoch="$(date -u +%s)"
mapfile_sets() { find "$BACKUP_DIR" -maxdepth 1 -type d -name 'set-*' | sort; }
total="$(mapfile_sets | wc -l | tr -d ' ')"
removed=0
index=0
while IFS= read -r dir; do
  index=$((index + 1))
  [ $((total - index)) -lt 3 ] && continue                       # the newest three stay whatever their age
  name="$(basename "$dir")"; ts="${name#set-}"; day="${ts:0:8}"
  if date -u -d "${day}" +%s >/dev/null 2>&1; then set_epoch="$(date -u -d "$day" +%s)"; else set_epoch="$(date -u -j -f %Y%m%d "$day" +%s 2>/dev/null || echo "$now_epoch")"; fi
  age_days=$(( (now_epoch - set_epoch) / 86400 ))
  limit="$KEEP_DAYS"
  [ "${day:6:2}" = "01" ] && limit=$(( KEEP_MONTHS * 31 ))
  if [ "$age_days" -gt "$limit" ]; then rm -rf "$dir"; removed=$((removed + 1)); fi
done < <(mapfile_sets)
log "retention: removed $removed old set(s); $(( total - removed )) kept"
printf '%s\n' "$SET"
