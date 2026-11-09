<?php

namespace Tests\Support;

use Symfony\Component\Process\Process;

/**
 * A backup restoration drill on throwaway databases, driven the way an operator would do it: build a real ledger with the demo seeds
 * (committed, in its own database), take a backup (pg_dump -Fc on PostgreSQL, a consistent copy with VACUUM INTO on SQLite), restore
 * it into a second empty database, and check the restored books with the application's own verifier (`finance:gl:verify`) and with
 * hard counts. It never touches the database the test suite runs on. The full production runbook (retention, off-site copies,
 * timing at volume) belongs to the performance agent's scripts/ops; this is the minimum that proves a backup can be restored and
 * that the restored books still tie out.
 */
final class BackupRestoreDrill
{
    /** @var list<string> */
    public array $log = [];

    private string $work;

    public function __construct(private readonly string $driver)
    {
        $this->work = sys_get_temp_dir().'/fin-drill-'.getmypid().'-'.bin2hex(random_bytes(3));
        mkdir($this->work);
    }

    public static function available(string $driver): bool
    {
        if ($driver === 'sqlite') {
            return true;
        }

        foreach (['pg_dump', 'pg_restore', 'createdb', 'dropdb', 'psql'] as $tool) {
            if ((new Process(['which', $tool]))->run() !== 0) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string> the environment that points a child artisan at one database */
    private function env(string $database): array
    {
        $base = ['APP_ENV' => 'local', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'DB_URL' => '', 'BROADCAST_CONNECTION' => 'null', 'PULSE_ENABLED' => 'false'];

        return $this->driver === 'sqlite'
            ? $base + ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database]
            : $base + ['DB_CONNECTION' => 'pgsql', 'DB_HOST' => $this->pg('host'), 'DB_PORT' => $this->pg('port'), 'DB_USERNAME' => $this->pg('username'), 'DB_PASSWORD' => $this->pg('password'), 'DB_DATABASE' => $database];
    }

    /** One setting of the PostgreSQL connection the running tests use (a socket directory on a developer machine, a host and password on CI). */
    private function pg(string $key): string
    {
        $config = (array) config('database.connections.pgsql');
        $default = ['host' => '127.0.0.1', 'port' => '5432', 'username' => get_current_user() ?: (string) getenv('USER'), 'password' => ''];

        return (string) ($config[$key] ?? $default[$key]);
    }

    /** @return list<string> the connection flags every PostgreSQL client tool gets */
    private function pgArgs(): array
    {
        return ['-h', $this->pg('host'), '-p', $this->pg('port'), '-U', $this->pg('username')];
    }

    /** @param list<string> $command */
    private function run(array $command, array $env = [], int $timeout = 300): Process
    {
        $process = new Process($command, base_path(), $env + $this->envFor(), null, $timeout);
        $process->run();
        $this->log[] = implode(' ', array_map(fn ($c) => str_contains($c, ' ') ? '"'.$c.'"' : $c, $command)).' => exit '.$process->getExitCode();

        return $process;
    }

    /** @return array<string, string> */
    private function envFor(): array
    {
        return array_merge(getenv() ?: [], ['PATH' => (string) getenv('PATH'), 'HOME' => (string) getenv('HOME'), 'PGPASSWORD' => $this->pg('password')]);
    }

    private function artisan(string $database, array $args): Process
    {
        return $this->run([PHP_BINARY, '-d', 'memory_limit=1G', 'artisan', ...$args], $this->env($database));
    }

    private function sql(string $database, string $statement): string
    {
        if ($this->driver === 'sqlite') {
            $pdo = new \PDO('sqlite:'.$database);

            return (string) $pdo->query($statement)->fetchColumn();
        }
        $p = $this->run(['psql', ...$this->pgArgs(), '-d', $database, '-At', '-c', $statement]);

        return trim($p->getOutput());
    }

    /**
     * @return array{source: array<string, mixed>, restored: array<string, mixed>, tampered_detected: bool, log: list<string>, backup_bytes: int, seconds: float}
     */
    public function run_(): array
    {
        $started = microtime(true);
        $suffix = getmypid().bin2hex(random_bytes(2));
        $source = $this->driver === 'sqlite' ? $this->work.'/source.sqlite' : 'finance_drill_src_'.$suffix;
        $restored = $this->driver === 'sqlite' ? $this->work.'/restored.sqlite' : 'finance_drill_dst_'.$suffix;
        $backup = $this->work.($this->driver === 'sqlite' ? '/backup.sqlite' : '/backup.dump');

        try {
            // 1. a real ledger in its own database
            if ($this->driver === 'sqlite') {
                touch($source);
            } else {
                $this->run(['createdb', ...$this->pgArgs(), $source]);
                $this->run(['createdb', ...$this->pgArgs(), $restored]);
            }
            $this->mustPass($this->artisan($source, ['migrate', '--force']), 'migrate');
            $this->mustPass($this->artisan($source, ['finance:demo-seed', '--sales']), 'demo seed with a sales history');
            $this->mustPass($this->artisan($source, ['finance:gl:verify']), 'the source books verify before the backup');
            $before = $this->evidence($source);

            // 2. the backup
            if ($this->driver === 'sqlite') {
                $pdo = new \PDO('sqlite:'.$source);
                $pdo->exec("VACUUM INTO '".$backup."'");
            } else {
                $this->mustPass($this->run(['pg_dump', ...$this->pgArgs(), '-Fc', '--no-owner', '-f', $backup, $source]), 'pg_dump -Fc');
            }

            // 3. the source is lost; restore the backup into a new database
            if ($this->driver === 'sqlite') {
                copy($backup, $restored);
            } else {
                $this->mustPass($this->run(['pg_restore', ...$this->pgArgs(), '--no-owner', '-d', $restored, $backup]), 'pg_restore');
            }

            // 4. the restored books are the same books and still verify
            $this->mustPass($this->artisan($restored, ['finance:gl:verify']), 'the RESTORED books verify');
            $after = $this->evidence($restored);

            // 5. the verifier is not a rubber stamp: change one cent in the restored copy and it must object
            $tamper = $this->driver === 'sqlite'
                ? 'DROP TRIGGER IF EXISTS journal_lines_no_update; UPDATE journal_lines SET debit_cents = debit_cents + 1 WHERE id = (SELECT MIN(id) FROM journal_lines WHERE debit_cents > 0)'
                : 'ALTER TABLE journal_lines DISABLE TRIGGER USER; UPDATE journal_lines SET debit_cents = debit_cents + 1 WHERE id = (SELECT MIN(id) FROM journal_lines WHERE debit_cents > 0); ALTER TABLE journal_lines ENABLE TRIGGER USER';
            if ($this->driver === 'sqlite') {
                $pdo = new \PDO('sqlite:'.$restored);
                $pdo->exec($tamper);
            } else {
                $this->run(['psql', ...$this->pgArgs(), '-d', $restored, '-c', $tamper]);
            }
            $tampered = $this->artisan($restored, ['finance:gl:verify'])->getExitCode() !== 0;

            return ['source' => $before, 'restored' => $after, 'tampered_detected' => $tampered, 'log' => $this->log, 'backup_bytes' => (int) filesize($backup), 'seconds' => round(microtime(true) - $started, 1)];
        } finally {
            if ($this->driver !== 'sqlite') {
                $this->run(['dropdb', ...$this->pgArgs(), '--if-exists', $source]);
                $this->run(['dropdb', ...$this->pgArgs(), '--if-exists', $restored]);
            }
            foreach (glob($this->work.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->work);
        }
    }

    /** @return array<string, mixed> */
    private function evidence(string $database): array
    {
        $tables = ['journal_entries', 'journal_lines', 'transactions', 'accounts', 'sales_documents', 'payments', 'parties', 'gl_accounts', 'finance_audit_log', 'users', 'teams'];
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = (int) $this->sql($database, "SELECT COUNT(*) FROM {$table}");
        }

        return [
            'counts' => $counts,
            'debits' => (int) $this->sql($database, 'SELECT COALESCE(SUM(debit_cents),0) FROM journal_lines'),
            'credits' => (int) $this->sql($database, 'SELECT COALESCE(SUM(credit_cents),0) FROM journal_lines'),
            'last_entry_hash' => $this->sql($database, 'SELECT entry_hash FROM journal_entries ORDER BY id DESC LIMIT 1'),
            'invoice_total_cents' => (int) $this->sql($database, 'SELECT COALESCE(SUM(total_cents),0) FROM sales_documents'),
        ];
    }

    private function mustPass(Process $process, string $what): void
    {
        if (! $process->isSuccessful()) {
            throw new \RuntimeException("Drill step failed: {$what}\n".$process->getErrorOutput()."\n".$process->getOutput());
        }
    }
}
