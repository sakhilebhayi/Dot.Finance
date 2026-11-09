<?php

namespace App\Services\Bank;

use App\Models\Account;
use App\Models\Bank\BankStatement;
use App\Models\User;
use App\Services\Bank\Parsing\CsvStatementReader;
use App\Services\Bank\Parsing\OfxStatementParser;
use App\Services\Bank\Parsing\QifStatementParser;
use App\Services\Bank\Parsing\RawStatement;
use App\Support\FinanceAudit;
use App\Support\ImportFingerprint;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Statement import in three steps that never trust the browser with row data (the same shape as the CSV transaction
 * import, kept separate because a statement is not a set of ledger rows: it is evidence to reconcile against):
 *
 *  1. stage()    keeps the uploaded file on the server under an unguessable token, bound to the ledger and account
 *  2. analyse()  re-reads it with the chosen column mapping, date order and number format and says, per row, what would
 *                happen (new, already held, unreadable); nothing is written
 *  3. commit()   re-reads it again and writes a statement and its new lines in one database transaction
 *
 * Formats: CSV (with a column mapping the person confirms), OFX / QFX and QIF files the person downloaded. There is no
 * bank feed here and none is simulated: a live feed needs a licensed provider, which is not connected (see docs/R44_BANK.md).
 *
 * Re-importing is safe twice over: the exact same file (same hash, same account) is recognised and changes nothing, and any
 * line already held (same fingerprint) is skipped, so overlapping statements add only what is new.
 */
class StatementImporter
{
    public const DISK = 'local';

    public const MAX_BYTES = 5 * 1024 * 1024;

    /** How many parsed rows the preview lists (counts always cover the whole file). */
    public const PREVIEW_ROWS = 200;

    private const INSERT_CHUNK = 400;

    public function __construct(
        private readonly CsvStatementReader $csv,
        private readonly OfxStatementParser $ofx,
        private readonly QifStatementParser $qif,
        private readonly StatementInterpreter $interpreter,
    ) {}

    // ── step 1 ────────────────────────────────────────────────────────────

    public function stage(User $user, LedgerContext $context, Account $account, UploadedFile $file): string
    {
        if (! $account->ledgerContext()->equals($context)) {
            throw new InvalidArgumentException('That account is not in the ledger you are importing into.');
        }

        $contents = (string) file_get_contents($file->getRealPath());
        $format = $this->sniff($file->getClientOriginalName(), $contents);
        $token = (string) Str::uuid();
        $dir = $this->dir($user);

        Storage::disk(self::DISK)->put("{$dir}/{$token}.dat", $contents);
        Storage::disk(self::DISK)->put("{$dir}/{$token}.json", json_encode([
            'account_id' => $account->id,
            'context' => $context->key(),
            'filename' => mb_substr(basename($file->getClientOriginalName()), 0, 150),
            'format' => $format,
            'sha256' => hash('sha256', $contents),
        ]));

        return $token;
    }

    /** @return array{account_id: int, filename: string, format: string, sha256: string, path: string}|null null when the token is not this person's, or was staged in another ledger */
    public function load(User $user, LedgerContext $context, string $token): ?array
    {
        if (preg_match('/^[0-9a-f-]{36}$/', $token) !== 1) {
            return null;
        }

        $disk = Storage::disk(self::DISK);
        $dir = $this->dir($user);

        if (! $disk->exists("{$dir}/{$token}.dat") || ! $disk->exists("{$dir}/{$token}.json")) {
            return null;
        }

        $meta = json_decode((string) $disk->get("{$dir}/{$token}.json"), true) ?: [];

        if (($meta['context'] ?? null) !== $context->key()) {
            return null;
        }

        return [
            'account_id' => (int) ($meta['account_id'] ?? 0),
            'filename' => (string) ($meta['filename'] ?? 'statement'),
            'format' => (string) ($meta['format'] ?? 'csv'),
            'sha256' => (string) ($meta['sha256'] ?? ''),
            'path' => $disk->path("{$dir}/{$token}.dat"),
        ];
    }

    public function discard(User $user, string $token): void
    {
        $dir = $this->dir($user);
        Storage::disk(self::DISK)->delete(["{$dir}/{$token}.dat", "{$dir}/{$token}.json"]);
    }

    // ── step 2 ────────────────────────────────────────────────────────────

    /**
     * @param  array{columns?: array<string, ?int>, has_header?: bool, date_format?: ?string, amount_locale?: ?string, invert?: bool, opening_cents?: ?int, closing_cents?: ?int}  $options
     * @return array<string, mixed>
     */
    public function analyse(User $user, LedgerContext $context, string $token, array $options = []): array
    {
        $staged = $this->load($user, $context, $token) ?? throw new InvalidArgumentException('Unknown import.');
        $account = Account::query()->forContext($context)->findOrFail($staged['account_id']);

        $csvInfo = null;
        $contents = (string) file_get_contents($staged['path']);

        if ($staged['format'] === 'ofx') {
            $raw = $this->ofx->parse($contents);
        } elseif ($staged['format'] === 'qif') {
            $raw = $this->qif->parse($contents);
        } else {
            $read = $this->csv->read($staged['path']);
            $records = $read['records'];
            $suggested = $this->csv->suggestMapping($records);
            $given = isset($options['columns']) ? [
                'has_header' => (bool) ($options['has_header'] ?? true),
                'columns' => $this->cleanColumns($options['columns']),
            ] : null;
            $mapping = $given ?? $suggested;
            $raw = $this->csv->toRaw($records, $mapping);
            $csvInfo = [
                'sample' => array_slice($records, 0, 8),
                'column_count' => max(array_map('count', $records ?: [[]])),
                'delimiter' => $read['delimiter'] === "\t" ? 'tab' : $read['delimiter'],
                'mapping' => $mapping,
                'suggested' => $given === null,
            ];
        }

        $interpreted = $this->interpreter->interpret($raw, $options['date_format'] ?? null, $options['amount_locale'] ?? null, (bool) ($options['invert'] ?? false));
        $rows = $interpreted['rows'];

        $problems = $raw->problems;
        if (isset($raw->hints['currency']) && $raw->hints['currency'] !== $account->currency) {
            $problems[] = "This file is in {$raw->hints['currency']} but the account holds {$account->currency}. Import it into an account in the same currency.";
        }
        if ($interpreted['date_format'] === null && $rows !== []) {
            $problems[] = 'Choose the date order: this file does not make it certain.';
        }
        if ($interpreted['amount_locale'] === null && $rows !== []) {
            $problems[] = 'Choose the number format: this file does not make it certain.';
        }

        $this->fingerprint($rows, $account);
        $this->markDuplicates($rows, $account);

        $valid = array_values(array_filter($rows, fn ($r) => $r['error'] === null));
        $counts = [
            'total' => count($rows),
            'invalid' => count($rows) - count($valid),
            'duplicates' => count(array_filter($valid, fn ($r) => $r['duplicate'])),
            'importable' => count(array_filter($valid, fn ($r) => ! $r['duplicate'])),
            'before_opening' => $account->opening_balance_date === null ? 0 : count(array_filter($valid, fn ($r) => $r['date'] < $account->opening_balance_date->toDateString())),
        ];

        $dates = $valid === [] ? ['from' => null, 'to' => null] : ['from' => min(array_column($valid, 'date')), 'to' => max(array_column($valid, 'date'))];
        $balances = $this->balances($rows, $valid, $raw, $options);

        $existing = $staged['sha256'] !== '' ? BankStatement::query()->forContext($context)->where('account_id', $account->id)->where('file_sha256', $staged['sha256'])->first() : null;

        $ready = $problems === [] && $valid !== [] && $interpreted['date_format'] !== null && $interpreted['amount_locale'] !== null;

        return [
            'token' => $token,
            'account' => $account,
            'filename' => $staged['filename'],
            'format' => $staged['format'],
            'sha256' => $staged['sha256'],
            'csv' => $csvInfo,
            'problems' => $problems,
            'date_format' => $interpreted['date_format'],
            'date_ambiguous' => $interpreted['date_ambiguous'],
            'detected_date_format' => $interpreted['detected_date_format'],
            'amount_locale' => $interpreted['amount_locale'],
            'amount_ambiguous' => $interpreted['amount_ambiguous'],
            'detected_amount_locale' => $interpreted['detected_amount_locale'],
            'invert' => (bool) ($options['invert'] ?? false),
            'rows' => $rows,
            'counts' => $counts,
            'dates' => $dates,
            'balances' => $balances,
            'already_imported' => $existing,
            'ready' => $ready,
        ];
    }

    // ── step 3 ────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $options  as analyse()
     * @return array{statement: BankStatement, imported: int, duplicates: int, invalid: int, already_imported: bool}
     *
     * @throws InvalidArgumentException when the file cannot be imported as it stands
     */
    public function commit(User $user, LedgerContext $context, string $token, array $options = []): array
    {
        $preview = $this->analyse($user, $context, $token, $options);

        if ($preview['already_imported'] instanceof BankStatement) {
            $this->discard($user, $token);

            return ['statement' => $preview['already_imported'], 'imported' => 0, 'duplicates' => 0, 'invalid' => 0, 'already_imported' => true];
        }

        if (! $preview['ready']) {
            throw new InvalidArgumentException($preview['problems'][0] ?? 'This file cannot be imported yet: check the column mapping, date order and number format.');
        }

        /** @var Account $account */
        $account = $preview['account'];
        $valid = array_values(array_filter($preview['rows'], fn ($r) => $r['error'] === null));
        $new = array_values(array_filter($valid, fn ($r) => ! $r['duplicate']));
        $balances = $preview['balances'];
        $owner = $account->ledgerOwner();

        $statement = DB::transaction(function () use ($user, $context, $preview, $account, $valid, $new, $balances, $owner) {
            // Serialise two imports into the same account (the unique indexes would stop a double write; this makes the
            // second one wait and then see the first one's lines as already held).
            Account::query()->forContext($context)->whereKey($account->id)->lockForUpdate()->first();

            $net = array_sum(array_column($valid, 'cents'));

            $statement = BankStatement::create($owner + [
                'account_id' => $account->id,
                'source' => $preview['format'],
                'original_name' => $preview['filename'],
                'file_sha256' => $preview['sha256'],
                'currency' => $account->currency,
                'date_from' => $preview['dates']['from'],
                'date_to' => $preview['dates']['to'],
                'opening_balance_cents' => $balances['opening'],
                'closing_balance_cents' => $balances['closing'],
                'balances_foot' => $balances['opening'] !== null && $balances['closing'] !== null ? $balances['closing'] === $balances['opening'] + $net : null,
                'line_count' => count($new),
                'duplicate_count' => count($valid) - count($new),
                'in_cents' => array_sum(array_filter(array_column($valid, 'cents'), fn ($c) => $c > 0)),
                'out_cents' => array_sum(array_filter(array_column($valid, 'cents'), fn ($c) => $c < 0)),
                'mapping' => $preview['csv']['mapping'] ?? null,
                'imported_by' => $user->id,
            ]);

            $now = now()->toDateTimeString();
            foreach (array_chunk($new, self::INSERT_CHUNK) as $chunk) {
                DB::table('bank_statement_lines')->insert(array_map(fn (array $r) => $owner + [
                    'bank_statement_id' => $statement->id,
                    'account_id' => $account->id,
                    'line_no' => $r['line'],
                    'posted_on' => $r['date'],
                    'amount_cents' => $r['cents'],
                    'currency' => $account->currency,
                    'description' => $r['description'],
                    'reference' => $r['reference'] !== null ? mb_substr($r['reference'], 0, 100) : null,
                    'external_id' => $r['external_id'] !== null ? mb_substr($r['external_id'], 0, 100) : null,
                    'balance_after_cents' => $r['balance'],
                    'fingerprint' => $r['fingerprint'],
                    'created_at' => $now,
                ], $chunk));
            }

            FinanceAudit::record($owner, 'imported', 'bank_statement', $statement->id, $statement->auditLabel(), [
                'file' => $preview['filename'],
                'account_id' => $account->id,
                'source' => $preview['format'],
                'rows_total' => $preview['counts']['total'],
                'imported' => count($new),
                'already_held' => count($valid) - count($new),
                'unreadable' => $preview['counts']['invalid'],
                'in_cents' => $statement->in_cents,
                'out_cents' => $statement->out_cents,
                'currency' => $account->currency,
            ], 'bank-import:'.$statement->id, $account->currency);

            return $statement;
        });

        $this->discard($user, $token);

        return ['statement' => $statement, 'imported' => count($new), 'duplicates' => count($valid) - count($new), 'invalid' => $preview['counts']['invalid'], 'already_imported' => false];
    }

    /**
     * Remove a statement nobody has used: none of its lines is matched or reconciled. A statement that has been worked on
     * stays, because it is evidence. Returns the number of lines removed.
     */
    public function discardUnused(BankStatement $statement): int
    {
        return DB::transaction(function () use ($statement) {
            $locked = BankStatement::query()->forContext($statement->ledgerContext())->whereKey($statement->id)->lockForUpdate()->firstOrFail();

            $used = DB::table('bank_statement_lines')->where('bank_statement_id', $locked->id)->where(fn ($q) => $q->whereNotNull('bank_match_id')->orWhereNotNull('reconciliation_id'))->exists()
                || DB::table('bank_reconciliations')->where('bank_statement_id', $locked->id)->exists();

            if ($used) {
                throw new InvalidArgumentException('This statement has been worked on (a line is matched or a reconciliation started from it), so it is kept as evidence.');
            }

            $removed = DB::table('bank_statement_lines')->where('bank_statement_id', $locked->id)->delete();

            FinanceAudit::record($locked->ledgerOwner(), 'discarded', 'bank_statement', $locked->id, $locked->auditLabel(), ['lines_removed' => $removed], null, $locked->currency);

            DB::table('bank_statements')->where('id', $locked->id)->delete();

            return $removed;
        });
    }

    // ── internals ───────────────────────────────────────────────────────────

    private function dir(User $user): string
    {
        // under finance-imports so the existing daily prune (finance:prune-imports) also clears abandoned bank uploads
        return "finance-imports/bank/{$user->id}";
    }

    private function sniff(string $name, string $contents): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (in_array($ext, ['ofx', 'qfx'], true) || stripos(substr($contents, 0, 2000), '<OFX') !== false) {
            return 'ofx';
        }
        if ($ext === 'qif' || str_starts_with(ltrim($contents), '!Type')) {
            return 'qif';
        }

        return 'csv';
    }

    /**
     * @param  array<string, mixed>  $columns
     * @return array<string, ?int>
     */
    private function cleanColumns(array $columns): array
    {
        $clean = [];
        foreach (CsvStatementReader::FIELDS as $field) {
            $value = $columns[$field] ?? null;
            $clean[$field] = ($value === null || $value === '' || ! is_numeric($value) || (int) $value < 0 || (int) $value > 60) ? null : (int) $value;
        }

        return $clean;
    }

    /** Fingerprint every readable row, counting identical rows in file order. */
    private function fingerprint(array &$rows, Account $account): void
    {
        $seen = [];

        foreach ($rows as &$row) {
            $row['fingerprint'] = null;
            $row['duplicate'] = false;

            if ($row['error'] !== null || $row['date'] === null || $row['cents'] === null) {
                continue;
            }

            $key = $row['date'].'|'.$row['cents'].'|'.ImportFingerprint::normalise((string) $row['description']);
            $seen[$key] = ($seen[$key] ?? 0) + 1;
            $row['fingerprint'] = StatementFingerprint::make($account->id, $row['date'], $row['cents'], (string) $row['description'], $seen[$key], $row['external_id']);
        }
        unset($row);
    }

    /** Flag the rows the account already holds: one query for the whole file, bounded by the file's dates. */
    private function markDuplicates(array &$rows, Account $account): void
    {
        $dates = array_filter(array_column($rows, 'date'));

        if ($dates === []) {
            return;
        }

        $held = DB::table('bank_statement_lines')
            ->where('account_id', $account->id)
            ->whereBetween('posted_on', [min($dates), max($dates)])
            ->pluck('fingerprint')
            ->flip();

        // A fingerprint that repeats inside the same file is two real lines (the occurrence counter told them apart).
        foreach ($rows as &$row) {
            $row['duplicate'] = $row['fingerprint'] !== null && isset($held[$row['fingerprint']]);
        }
        unset($row);
    }

    /**
     * Opening and closing balance with where each came from: typed by the person, stated by the file, or proved by its
     * running-balance column. Never invented.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $valid
     * @return array{opening: ?int, closing: ?int, opening_source: string, closing_source: string, foot: ?bool, net: int}
     */
    private function balances(array $rows, array $valid, RawStatement $raw, array $options): array
    {
        $chain = BalanceChain::derive($rows);
        $opening = $options['opening_cents'] ?? null;
        $closing = $options['closing_cents'] ?? null;
        $openingSource = $opening !== null ? 'typed' : 'none';
        $closingSource = $closing !== null ? 'typed' : 'none';

        if ($closing === null && isset($raw->hints['closing_cents'])) {
            $closing = $raw->hints['closing_cents'];
            $closingSource = 'file';
        }
        if ($closing === null && $chain['closing'] !== null) {
            $closing = $chain['closing'];
            $closingSource = 'running balance';
        }
        if ($opening === null && $chain['opening'] !== null) {
            $opening = $chain['opening'];
            $openingSource = 'running balance';
        }

        $net = array_sum(array_column($valid, 'cents'));

        return [
            'opening' => $opening,
            'closing' => $closing,
            'opening_source' => $openingSource,
            'closing_source' => $closingSource,
            'foot' => $opening !== null && $closing !== null ? $opening + $net === $closing : null,
            'net' => $net,
        ];
    }

    public static function parseTypedBalance(?string $value): ?int
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : Money::parseLocalized($value, 'en');
    }
}
