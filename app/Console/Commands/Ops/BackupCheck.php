<?php

namespace App\Console\Commands\Ops;

use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The watchdog for the nightly backup (scripts/ops/backup.sh). A backup that quietly stops is the usual way to find out you
 * have none, so this reads the newest set in BACKUP_DIR and fails (non-zero, which the scheduler watchdog reports) when
 * there is no set, the newest is older than BACKUP_MAX_AGE_HOURS, a file is missing, or a file no longer matches SHA256SUMS.
 * It reads only; it never makes, restores or deletes a backup. Inert while BACKUP_DIR is unset.
 */
#[Signature('finance:ops:backup-check {--dir= : Override BACKUP_DIR} {--max-age= : Override BACKUP_MAX_AGE_HOURS}')]
#[Description('Fail when the newest backup set is missing, too old, incomplete or no longer matches its checksums')]
class BackupCheck extends Command
{
    public function handle(): int
    {
        $dir = (string) ($this->option('dir') ?: config('ops.backup_dir'));
        $maxAge = (int) ($this->option('max-age') ?: config('ops.backup_max_age_hours'));

        if ($dir === '') {
            $this->line('BACKUP_DIR is not set: nothing to check. Set it to the folder scripts/ops/backup.sh writes to.');

            return self::SUCCESS;
        }

        $sets = glob(rtrim($dir, '/').'/set-*', GLOB_ONLYDIR) ?: [];
        sort($sets);
        $newest = end($sets) ?: null;
        if ($newest === null) {
            $this->error("No backup set in {$dir}.");

            return self::FAILURE;
        }

        $problems = [];
        if (! preg_match('/set-(\d{8}T\d{6})Z$/', $newest, $m)) {
            $problems[] = 'the newest folder name is not a backup timestamp: '.basename($newest);
        } else {
            $made = CarbonImmutable::createFromFormat('Ymd\THis', $m[1], 'UTC');
            $hours = (int) $made->diffInHours(now('UTC'), true);
            if ($hours > $maxAge) {
                $problems[] = "the newest set is {$hours} hours old (limit {$maxAge}): ".basename($newest);
            }
        }

        $hasDump = is_file("{$newest}/database.dump") || is_file("{$newest}/database.dump.enc") || is_file("{$newest}/database.sql.gz") || is_file("{$newest}/database.sql.gz.enc") || is_file("{$newest}/database.sqlite") || is_file("{$newest}/database.sqlite.enc");
        if (! $hasDump) {
            $problems[] = 'the set holds no database dump';
        }
        if (! is_file("{$newest}/env.backup") && ! is_file("{$newest}/env.backup.enc")) {
            $problems[] = 'the set holds no copy of .env (APP_KEY); encrypted bank details cannot be read without it';
        }

        $sums = "{$newest}/SHA256SUMS";
        if (! is_file($sums)) {
            $problems[] = 'the set has no SHA256SUMS';
        } else {
            foreach (file($sums, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (! preg_match('/^([0-9a-f]{64})\s+\*?(.+)$/', $line, $p)) {
                    continue;
                }
                $file = "{$newest}/{$p[2]}";
                if (! is_file($file)) {
                    $problems[] = "{$p[2]} is listed but missing";
                } elseif (! hash_equals($p[1], hash_file('sha256', $file))) {
                    $problems[] = "{$p[2]} does not match its checksum";
                }
            }
        }

        if ($problems !== []) {
            foreach ($problems as $p) {
                $this->error($p);
            }

            return self::FAILURE;
        }

        $this->info('Newest backup set is fresh and intact: '.basename($newest));

        return self::SUCCESS;
    }
}
