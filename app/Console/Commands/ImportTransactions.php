<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\User;
use App\Services\TransactionImporter;
use App\Support\Ledger\LedgerContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * The same importer as the Import page, run from the command line for a file too big for one web request. It reads the file
 * a row at a time and writes 500 rows per database transaction, so memory stays flat and a crash leaves whole chunks saved;
 * running the same file again skips every row already imported (each row's fingerprint is unique), so it is safe to re-run.
 */
#[Signature('finance:transactions:import {file : CSV file with date, description and amount columns} {--account= : Cash account id} {--ledger= : company:ID or personal:ID} {--user= : Id of the person recorded as importing} {--dry-run : Read and count, write nothing}')]
#[Description('Import a bank CSV into a cash account from the command line (no row limit; re-running skips rows already imported)')]
class ImportTransactions extends Command
{
    public function handle(TransactionImporter $importer): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("No such file: {$path}");

            return self::FAILURE;
        }

        [$kind, $id] = array_pad(explode(':', (string) $this->option('ledger')), 2, null);
        if (! in_array($kind, ['company', 'personal'], true) || ! ctype_digit((string) $id)) {
            $this->error('Name the ledger: --ledger=company:ID or --ledger=personal:ID');

            return self::FAILURE;
        }
        $context = $kind === 'company' ? LedgerContext::fromOwner(null, (int) $id) : LedgerContext::fromOwner((int) $id, null);

        return LedgerContext::run($context, function () use ($importer, $path, $context) {
            $account = Account::query()->find((int) $this->option('account'));
            if ($account === null) {
                $this->error('That account is not in this ledger.');

                return self::FAILURE;
            }
            $user = User::query()->find((int) ($this->option('user') ?: ($context->userId ?? $account->ledgerContext()->userId ?? 0))) ?? User::query()->orderBy('id')->first();
            if ($user === null) {
                $this->error('There is no user to record the import against (--user=ID).');

                return self::FAILURE;
            }

            $importer->withoutRowLimit();
            try {
                $token = $importer->stage($user, $context, $account, new UploadedFile($path, basename($path), 'text/csv', null, true));
                $preview = $importer->analyse($user, $context, $token);
            } catch (InvalidArgumentException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $c = $preview['counts'];
            $this->line(sprintf('%s into "%s": %d rows, %d to import, %d already imported, %d unreadable.', basename($path), $account->name, $c['total'], $c['importable'], $c['duplicates'], $c['invalid']));
            if (! $preview['ready']) {
                $importer->discard($user, $token);
                $this->error('The file cannot be imported as it stands: '.($preview['date_format'] === null ? 'its date format is ambiguous or unknown. ' : '').($preview['amount_locale'] === null ? 'its amount format is ambiguous or unknown. ' : '').'Check the header row (date, description, amount).');

                return self::FAILURE;
            }
            if ($this->option('dry-run')) {
                $importer->discard($user, $token);
                $this->info('Dry run: nothing written.');

                return self::SUCCESS;
            }

            $result = $importer->commit($user, $context, $token, $preview['date_format'], $preview['amount_locale'], null, []);
            $this->info(sprintf('Imported %d; skipped %d already imported, %d unreadable.', $result['imported'], $result['skipped_duplicates'], $result['skipped_invalid']));

            return self::SUCCESS;
        });
    }
}
