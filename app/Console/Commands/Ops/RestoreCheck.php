<?php

namespace App\Console\Commands\Ops;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Used by scripts/ops/restore-drill.sh after a restore: names the database this process is connected to (so the drill can
 * refuse to run its checks against anything but the throwaway database) and proves the APP_KEY in use can read the
 * encrypted columns, one sample from each. Read only.
 */
#[Signature('finance:ops:restore-check {--expect-database= : Fail unless connected to this database}')]
#[Description('Name the connected database and prove the APP_KEY decrypts the encrypted columns (restore drill helper)')]
class RestoreCheck extends Command
{
    /** table => column, every column the models cast as encrypted */
    private const ENCRYPTED = [
        'supplier_bank_details' => 'payload',
        'sales_profiles' => 'bank_account_number',
        'tax_payroll_lines' => 'employee_name',
    ];

    public function handle(): int
    {
        $database = (string) DB::connection()->getDatabaseName();
        $this->line("database: {$database}");

        if (($expect = $this->option('expect-database')) && $expect !== $database) {
            $this->error("Connected to {$database}, expected {$expect}.");

            return self::FAILURE;
        }

        $failed = false;
        foreach (self::ENCRYPTED as $table => $column) {
            $row = DB::table($table)->whereNotNull($column)->first([$column]);
            if ($row === null) {
                $this->line("encrypted {$table}.{$column}: no rows to test");

                continue;
            }
            try {
                Crypt::decryptString($row->{$column});
                $this->line("encrypted {$table}.{$column}: decrypts");
            } catch (DecryptException) {
                $this->error("encrypted {$table}.{$column}: DOES NOT DECRYPT with this APP_KEY");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
