# Dot.Finance: backup and restore

Written for the owner and for whoever is on call. Everything here was run (see "What was demonstrated") except where it says
**not verified**. The two scripts are `scripts/ops/backup.sh` and `scripts/ops/restore-drill.sh`; the watchdog is
`php artisan finance:ops:backup-check`.

## 1. What a backup must hold

A restore needs four things. Lose any one and the restore is partial.

| Piece | Where it lives | Backed up by | If it is lost |
|---|---|---|---|
| The database (every ledger, journal, document, audit row) | PostgreSQL | `database.dump` (`pg_dump -Fc`) | The books are gone. Nothing else can rebuild them. |
| Private files (attachments, receipts, tax documents, report exports, logos) | `storage/app/private` and `storage/app/public` | `storage.tar.gz` | Rows that point at files point at nothing. Report exports are rebuilt on request and are left out of the archive. |
| `.env`, above all **`APP_KEY`** | the app folder on the host | `env.backup` | See the next section. |
| The code | git | the repository, and `deploy.yml`'s rollback tarball | Redeploy the tagged release. Not part of the backup set. |

The set also carries `row-counts.tsv` (the exact row count of all 99 tables at dump time), `ledger-heads.tsv` (for each ledger, the
newest journal entry number, its hash and the entry count: the head of the hash chain), `MANIFEST.txt` and `SHA256SUMS`. The restore
drill uses the first two to prove the restored database is the one that was dumped.

### Losing `APP_KEY` loses data

Laravel encrypts some columns with `APP_KEY`. The database dump holds them as ciphertext, and **without the key they cannot be read, ever**:

- `supplier_bank_details.payload`: supplier bank account details (the whole detail set).
- `sales_profiles.bank_account_number`: the company's own bank account number on invoices.
- `tax_payroll_lines.employee_name`: employee names on payroll summaries.
- `users.two_factor_secret` and the recovery codes: every person with two-factor sign-in would be locked out.

Also derived from the key: the keyed hash that spots two suppliers sharing one bank account (`account_hash`), and session and
signed-link cookies (these only log people out). So: **`env.backup` is as important as the database.** It is stored beside the dump
in every set, and the restore drill proves the key in the set decrypts a real encrypted column (step 6). Keep one more copy of
`APP_KEY` in a password manager, outside the server and outside the backup folder; a backup that sits next to the only copy of its own
key is protected against a disk failure but not against losing the host.

Do not put a different key into a restored environment "to be safe": data written with the old key will not decrypt with the new one.

## 2. How often, how long, where

- **Every night**, off-peak, from cron (the host's crontab is shared with the other Dot apps: add the line through the same
  `flock`-guarded step the deploy uses, or by hand with care):

  ```
  30 2 * * *  cd /path/to/finance && BACKUP_DIR=$HOME/backups/dot-finance BACKUP_PASSPHRASE_FILE=$HOME/.finance-backup-pass scripts/ops/backup.sh >> storage/logs/cron-backup.log 2>&1 # dot-finance-backup
  ```

  Pick a time that does not collide with the app's own nightly jobs (post-pending 02:10, verify 02:40, intelligence scan 03:10).
- **Before every deploy that migrates**: run `scripts/ops/backup.sh` by hand. The deploy's rollback tarball holds code only, not the
  database. (`docs/deployment.md` says the same.)
- **Retention** (built into the script, adjustable with `BACKUP_KEEP_DAYS` and `BACKUP_KEEP_MONTHLY`): every set for 14 days, the
  first set of each month for 12 months, and the newest three sets are never removed whatever their age. At the size measured below
  (45 MB per set for a 200,000-row ledger) that is under 2 GB.
- **Off the host**: a backup on the same disk as the database protects against a mistake, not against losing the machine. Copy the
  newest set to storage somewhere else after each run (an object-storage bucket, another server). The script deliberately does not
  guess where; `rclone copy`, `rsync` or the host's own backup service all work on the `set-*` folders.
- **Encryption**: a set holds personal data and the application key. Set `BACKUP_PASSPHRASE_FILE` (a file, mode 600, holding a long
  passphrase kept in the password manager too) and every file in the set is encrypted with `openssl` AES-256 (PBKDF2). Without it
  the set is plain: keep the folder at mode 700 (the script does) and out of every web root.

## 3. How to know it worked

1. **The watchdog.** `finance:ops:backup-check` is scheduled daily at 08:15. It fails, and so reaches the scheduler watchdog, when
   there is no set, the newest is older than `BACKUP_MAX_AGE_HOURS` (default 30), the set has no database dump or no `.env` copy, a
   file is missing, or a file no longer matches `SHA256SUMS`. It does nothing until `BACKUP_DIR` is set in `.env`. It checks that a
   backup exists and is intact; it does not restore it (that is the drill).
2. **The restore drill, monthly** (and after any change to the backup script, PostgreSQL version or host):

   ```
   BACKUP_DIR=$HOME/backups/dot-finance scripts/ops/restore-drill.sh        # newest set, or pass a set folder
   ```

   It restores into a throwaway database `restore_drill_<timestamp>`, never into or over the live database (it refuses a name that is
   not `restore_drill_<digits>` or that equals the source database), and checks, in order:

   0. every file matches its checksum;
   1. `pg_restore` completes;
   2. every table's row count equals the count recorded at backup time;
   3. each ledger's newest journal entry number, hash and entry count equal the recorded head;
   4. the append-only triggers exist again and an `UPDATE` of a posted line and a `DELETE` of a posted entry are refused;
   5. `finance:gl:verify` (balanced entries, trial balance, cash tie-out, gap-free numbers **and the hash chain**, periods, isolation),
      `finance:check-balances` and `sales:check-receivables` pass, run by the application pointed at the restored database;
   6. the `APP_KEY` from the set's `.env` copy decrypts a real encrypted column.

   It prints one line per check and exits non-zero if any failed, then drops the throwaway database (`KEEP_DRILL_DB=1` keeps it).
   The role needs `CREATEDB`; on a host where it does not, create an empty database called `restore_drill_<14 digits>` in the hosting
   panel and run with `DRILL_DB=` set to that name.

   **Safety against pointing the checks at the live database.** The application caches its configuration in production, and a cached
   configuration ignores environment variables. The drill sets `APP_CONFIG_CACHE` to a file that does not exist so the checks read the
   environment, then asks the application which database it will use (`finance:ops:restore-check`) and refuses to run the checks if
   the answer is not the throwaway one.

## 4. Restoring for real

Do this in order. Nothing here is done by a script on purpose: a real restore replaces live data and a person should be doing it.

1. Put the site in maintenance mode (`php artisan down`) so nobody writes while you work.
2. Keep the damaged database: rename it (`ALTER DATABASE finance RENAME TO finance_damaged_<date>`) rather than dropping it.
3. Create an empty database with the original name and owner, then
   `pg_restore -d finance --no-owner --no-privileges --exit-on-error database.dump`
   (decrypt first with `openssl enc -d -aes-256-cbc -pbkdf2 -pass file:PASSFILE -in database.dump.enc -out database.dump` if the set is encrypted).
4. **Run `ANALYZE` (or `vacuumdb --analyze-only`) before anything else.** A dump holds rows, not planner statistics. Measured on the
   200,000-row ledger, `finance:gl:verify` took 23 s on the freshly restored database against 5 s on the live one, and
   `sales:check-receivables` took 70 s against under 1 s, until `ANALYZE` had run. The drill runs it for you and prints how long it took.
5. Put `env.backup` back as `.env` (or at least its `APP_KEY`), unpack `storage.tar.gz` over `storage/` , and check the file owner and mode.
6. Deploy the release that matches the backup's `code_version` (in `MANIFEST.txt`), or a newer one and let its migrations run.
7. Run `php artisan finance:gl:verify`, `finance:check-balances` and `sales:check-receivables`. All three must pass before the site is opened.
   If `finance:gl:verify` reports pending cash-ledger rows, `finance:gl:post-pending` posts them (it is idempotent).
8. `php artisan up`. Then open the Command centre and one report and read the figures.

Anything entered between the last backup and the failure is lost; say so to the people who entered it, and list what the audit log
(`/audit`) shows for the damaged copy that is newer than the set.

## 5. Point-in-time recovery and managed PostgreSQL

A nightly dump gives a recovery point at most 24 hours old. For a ledger where a day of entries is too much to lose, add continuous
archiving underneath it:

- **Managed PostgreSQL (RDS, Cloud SQL, Azure, DigitalOcean, Supabase, Neon):** switch on the provider's automated backups with
  point-in-time recovery and set the window to at least 7 days (14 is better). The provider restores to a *new instance* at a chosen
  second; point the application at it, or `pg_dump` the recovered database and load it into the original. Run the restore drill's
  step 5 against the recovered instance: PITR proves the database came back, the checks prove the books are intact.
- **Self-hosted PostgreSQL:** `wal_level = replica`, `archive_mode = on` and an `archive_command` that ships WAL to storage off the
  host (pgBackRest or WAL-G do this and verify it), plus a weekly base backup. Recovery is a base backup plus WAL replayed to a
  `recovery_target_time`.
- PITR does not cover `storage/app` or `.env`: the nightly file archive and the key copy still matter.
- The journals are append-only and hash-chained, so a point-in-time restore cannot produce a half-posted entry: an entry and its lines are
  one transaction, and the head of the chain after a restore is always some earlier complete entry. The chain check in step 5 proves it.

## 6. The cPanel host: what is true and what is not verified

The host is shared cPanel hosting (`docs/deployment.md`). **Nothing below could be checked from here**; check it on the host before
trusting the schedule.

- Whether `pg_dump`, `pg_restore`, `psql`, `createdb` and `dropdb` are installed and on `PATH` for the cron user. If not, set `PG_DUMP`,
  `PSQL`, `PG_RESTORE`, `CREATEDB` and `DROPDB` to full paths, or ask the host. If PostgreSQL is only reachable over the network from
  another server, run the backup from that server instead (the script only needs the database host, user and password).
- Whether the version of `pg_dump` is not older than the server (a `pg_dump` older than the server refuses to run).
- If the production database is actually MySQL: `backup.sh` dumps it with `mysqldump --single-transaction --routines --triggers` and
  checks the gzip; `restore-drill.sh` is PostgreSQL-only and says so. Restore the `.sql.gz` into a scratch database by hand and run the
  three application checks with `DB_DATABASE` pointed at it. The append-only triggers are created for both engines by the migrations.
- Whether the account's cron can run a job for minutes (the nightly dump of a 500 MB database took 5 seconds here; a shared host
  throttles CPU, so measure) and whether `flock` exists (the script falls back to a lock directory when it does not).
- Disk quota: a 200,000-row ledger dumped to 37.5 MB and the storage archive to 9 MB. Fourteen daily sets and twelve monthly ones fit
  in about 2 GB. Check the account's quota and the free space.
- Whether the hosting panel's own account backup also covers the database (many cPanel plans back up MySQL only). Treat it as a bonus,
  never as the plan.
- The host's `php.ini` `memory_limit` for the CLI (the commands in `docs/R44_QA_perf.md` were all proven to run in 128 MB).

## 7. What was demonstrated

On a throwaway PostgreSQL 16 database holding the generated 200,000-row company ledger (`docs/R44_QA_perf.md` section 5): backup in
5 s (45 MB set), restore into `restore_drill_<ts>` in 13 s, all 99 table counts equal, both ledger heads and their hashes equal,
the four journal triggers present and refusing an `UPDATE` and a `DELETE`, `finance:gl:verify` (hash chain included),
`finance:check-balances` and `sales:check-receivables` passing against the restored database, and the key from the set decrypting an
encrypted column. All throwaway databases were dropped afterwards.
