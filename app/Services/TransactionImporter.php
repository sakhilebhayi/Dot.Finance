<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Support\FinanceAudit;
use App\Support\ImportDates;
use App\Support\ImportFingerprint;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * CSV import as three steps that never trust the browser with row data:
 *
 *  1. stage()   keeps the uploaded file on the server under an unguessable token
 *  2. analyse() re-reads it with the user's chosen date and number formats and
 *               says, per row, what would happen (import, already imported, invalid)
 *  3. commit()  re-reads it again and writes the importable rows in ONE database
 *               transaction, then logs a single summary row to the audit log
 *
 * Duplicates are caught by a per-row fingerprint stored on the transaction with a
 * unique index, so re-importing the same or an overlapping statement is safe.
 *
 * A staged file belongs to the ledger it was staged in (a person's or one company's):
 * it can be previewed and imported only from that ledger, and only into an account that
 * ledger owns.
 */
class TransactionImporter
{
    public const DISK = 'local';

    /** How many parsed rows the preview page lists (counts always cover the whole file). */
    public const PREVIEW_ROWS = 300;

    public function __construct(private readonly CsvTransactionParser $parser) {}

    // ── step 1 ────────────────────────────────────────────────────────────

    public function stage(User $user, LedgerContext $context, Account $account, UploadedFile $file): string
    {
        if (! $account->ledgerContext()->equals($context)) {
            throw new InvalidArgumentException('That account is not in the ledger you are importing into.');
        }

        $token = (string) Str::uuid();
        $dir = "finance-imports/{$user->id}";

        Storage::disk(self::DISK)->putFileAs($dir, $file, "{$token}.csv");
        Storage::disk(self::DISK)->put("{$dir}/{$token}.json", json_encode([
            'account_id' => $account->id,
            'context' => $context->key(),
            'filename' => mb_substr(basename($file->getClientOriginalName()), 0, 150),
        ]));

        return $token;
    }

    /** @return array{account_id: int, filename: string, path: string}|null  null when the token is not this person's, or was staged in another ledger */
    public function load(User $user, LedgerContext $context, string $token): ?array
    {
        if (preg_match('/^[0-9a-f-]{36}$/', $token) !== 1) {
            return null;
        }

        $disk = Storage::disk(self::DISK);
        $dir = "finance-imports/{$user->id}";

        if (! $disk->exists("{$dir}/{$token}.csv") || ! $disk->exists("{$dir}/{$token}.json")) {
            return null;
        }

        $meta = json_decode((string) $disk->get("{$dir}/{$token}.json"), true) ?: [];

        if (($meta['context'] ?? null) !== $context->key()) {
            return null;
        }

        return [
            'account_id' => (int) ($meta['account_id'] ?? 0),
            'filename' => (string) ($meta['filename'] ?? 'import.csv'),
            'path' => $disk->path("{$dir}/{$token}.csv"),
        ];
    }

    public function discard(User $user, string $token): void
    {
        Storage::disk(self::DISK)->delete(["finance-imports/{$user->id}/{$token}.csv", "finance-imports/{$user->id}/{$token}.json"]);
    }

    /** Delete staged files nobody finished importing. Returns how many files went. */
    public function prune(int $olderThanHours = 24): int
    {
        $disk = Storage::disk(self::DISK);
        $cutoff = now()->subHours($olderThanHours)->getTimestamp();
        $deleted = 0;

        foreach ($disk->allFiles('finance-imports') as $file) {
            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
                $deleted++;
            }
        }

        return $deleted;
    }

    // ── step 2 ────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function analyse(User $user, LedgerContext $context, string $token, ?string $dateFormat = null, ?string $amountLocale = null, ?int $defaultCategoryId = null): array
    {
        $staged = $this->load($user, $context, $token) ?? throw new InvalidArgumentException('Unknown import.');
        $account = Account::query()->forContext($context)->findOrFail($staged['account_id']);

        $file = $this->parser->read($staged['path']);

        $detectedDate = ImportDates::detect(array_values(array_filter(array_column($file['rows'], 'raw_date'))));
        $detectedAmount = Money::detectLocale(array_values(array_filter(array_column($file['rows'], 'raw_amount'))));

        $dateFormat = in_array($dateFormat, ImportDates::FORMATS, true) ? $dateFormat : $detectedDate['format'];
        $amountLocale = in_array($amountLocale, ['en', 'eu'], true) ? $amountLocale : $detectedAmount['locale'];

        $categories = Category::query()->forContext($context)->get()->keyBy(fn (Category $c) => mb_strtolower($c->name));
        $defaultCategory = $defaultCategoryId ? Category::query()->forContext($context)->find($defaultCategoryId) : null;

        $rows = [];
        foreach ($file['rows'] as $raw) {
            $rows[] = $this->interpret($raw, $dateFormat, $amountLocale, $categories, $defaultCategory);
        }

        $this->markDuplicates($rows, $account);

        $columnsOk = $file['columns_found']['date'] && $file['columns_found']['description'] && $file['columns_found']['amount'];
        $ready = $columnsOk && $dateFormat !== null && $amountLocale !== null;

        $counts = [
            'total' => count($rows),
            'invalid' => count(array_filter($rows, fn ($r) => $r['error'] !== null)),
            'duplicates' => count(array_filter($rows, fn ($r) => $r['error'] === null && $r['duplicate'])),
            'importable' => count(array_filter($rows, fn ($r) => $r['error'] === null && ! $r['duplicate'] && $r['date'] !== null && $r['cents'] !== null)),
        ];

        return [
            'token' => $token,
            'account' => $account,
            'filename' => $staged['filename'],
            'columns_found' => $file['columns_found'],
            'detected_date_format' => $detectedDate['format'],
            'date_ambiguous' => $detectedDate['ambiguous'],
            'date_format' => $dateFormat,
            'detected_amount_locale' => $detectedAmount['locale'],
            'amount_ambiguous' => $detectedAmount['ambiguous'],
            'amount_locale' => $amountLocale,
            'default_category_id' => $defaultCategory?->id,
            'rows' => $rows,
            'counts' => $counts,
            'ready' => $ready,
        ];
    }

    /**
     * @param  array{line: int, description: ?string, raw_date: ?string, raw_amount: ?string, category_name: ?string}  $raw
     * @return array<string, mixed>
     */
    private function interpret(array $raw, ?string $dateFormat, ?string $amountLocale, $categories, ?Category $defaultCategory): array
    {
        $row = $raw + [
            'date' => null, 'cents' => null, 'type' => null, 'category_id' => null, 'category_note' => null,
            'error' => null, 'duplicate' => false, 'fingerprint' => null,
        ];

        if ($raw['description'] === null) {
            $row['error'] = 'Missing description.';
        }

        if ($raw['raw_date'] === null) {
            $row['error'] ??= 'Missing date.';
        } elseif ($dateFormat !== null) {
            $row['date'] = ImportDates::parse($raw['raw_date'], $dateFormat);

            if ($row['date'] === null) {
                $row['error'] ??= "\"{$raw['raw_date']}\" is not a valid ".ImportDates::LABELS[$dateFormat].' date.';
            }
        }

        if ($raw['raw_amount'] === null) {
            $row['error'] ??= 'Missing amount.';
        } elseif ($amountLocale !== null) {
            try {
                $row['cents'] = Money::parseLocalized($raw['raw_amount'], $amountLocale);

                if ($row['cents'] === 0) {
                    $row['cents'] = null;
                    $row['error'] ??= 'The amount is zero.';
                }
            } catch (InvalidArgumentException) {
                $row['error'] ??= "\"{$raw['raw_amount']}\" is not a valid amount in this number format.";
            }
        }

        // The sign is the only signal for the type: the ledger stores a positive
        // magnitude and a separate type.
        if ($row['cents'] !== null) {
            $row['type'] = $row['cents'] < 0 ? 'expense' : 'income';
            $this->matchCategory($row, $categories, $defaultCategory);
        }

        return $row;
    }

    /** A category is only used when its type fits the row; otherwise it is dropped, with a note. */
    private function matchCategory(array &$row, $categories, ?Category $defaultCategory): void
    {
        $named = $row['category_name'] !== null ? $categories->get(mb_strtolower($row['category_name'])) : null;

        if ($named !== null && $named->type === $row['type']) {
            $row['category_id'] = $named->id;

            return;
        }

        if ($named !== null) {
            $row['category_note'] = "\"{$named->name}\" is an {$named->type} category and this row is an {$row['type']}, so it was left uncategorised.";
        } elseif ($row['category_name'] !== null) {
            $row['category_note'] = "No category named \"{$row['category_name']}\".";
        }

        if ($defaultCategory !== null && $defaultCategory->type === $row['type']) {
            $row['category_id'] = $defaultCategory->id;
        }
    }

    /**
     * Fingerprint every readable row (counting identical rows in file order) and
     * flag the ones the ledger already holds. One query for the whole file.
     */
    private function markDuplicates(array &$rows, Account $account): void
    {
        $seen = [];

        foreach ($rows as &$row) {
            if ($row['error'] !== null || $row['date'] === null || $row['cents'] === null) {
                continue;
            }

            $signed = $row['cents'];
            $key = $row['date'].'|'.$signed.'|'.ImportFingerprint::normalise((string) $row['description']);
            $seen[$key] = ($seen[$key] ?? 0) + 1;

            $row['fingerprint'] = ImportFingerprint::make($account->id, $row['date'], $signed, (string) $row['description'], $seen[$key]);
        }
        unset($row);

        $fingerprints = array_values(array_filter(array_column($rows, 'fingerprint')));

        if ($fingerprints === []) {
            return;
        }

        $existing = [];
        foreach (array_chunk($fingerprints, 500) as $chunk) {
            foreach (Transaction::query()->forContext($account->ledgerContext())->whereIn('import_fingerprint', $chunk)->pluck('import_fingerprint') as $found) {
                $existing[$found] = true;
            }
        }

        foreach ($rows as &$row) {
            $row['duplicate'] = $row['fingerprint'] !== null && isset($existing[$row['fingerprint']]);
        }
        unset($row);
    }

    // ── step 3 ────────────────────────────────────────────────────────────

    /**
     * @param  list<int>  $skipLines  file line numbers the user unticked
     * @return array{imported: int, skipped_duplicates: int, skipped_invalid: int, skipped_by_user: int}
     */
    public function commit(User $user, LedgerContext $context, string $token, string $dateFormat, string $amountLocale, ?int $defaultCategoryId, array $skipLines): array
    {
        $preview = $this->analyse($user, $context, $token, $dateFormat, $amountLocale, $defaultCategoryId);
        $account = $preview['account'];
        $batch = (string) Str::uuid();
        $skip = array_flip($skipLines);

        $result = ['imported' => 0, 'skipped_duplicates' => 0, 'skipped_invalid' => 0, 'skipped_by_user' => 0];
        $income = 0;
        $expense = 0;

        DB::transaction(function () use ($preview, $account, $batch, $skip, $dateFormat, $amountLocale, &$result, &$income, &$expense) {
            FinanceAudit::withoutRowLogging(function () use ($preview, $account, $batch, $skip, &$result, &$income, &$expense) {
                foreach ($preview['rows'] as $row) {
                    if ($row['error'] !== null || $row['date'] === null || $row['cents'] === null) {
                        $result['skipped_invalid']++;

                        continue;
                    }

                    if ($row['duplicate']) {
                        $result['skipped_duplicates']++;

                        continue;
                    }

                    if (isset($skip[$row['line']])) {
                        $result['skipped_by_user']++;

                        continue;
                    }

                    Transaction::create($account->ledgerOwner() + [
                        'account_id' => $account->id,
                        'category_id' => $row['category_id'],
                        'description' => $row['description'],
                        'amount_cents' => abs($row['cents']),
                        'currency' => $account->currency,
                        'type' => $row['type'],
                        'date' => $row['date'],
                        'import_fingerprint' => $row['fingerprint'],
                        'import_batch_id' => $batch,
                    ]);

                    $result['imported']++;
                    $row['type'] === 'income' ? $income += $row['cents'] : $expense += abs($row['cents']);
                }
            });

            FinanceAudit::record($account->ledgerOwner(), 'imported', 'import', null, $preview['filename'], [
                'file' => $preview['filename'],
                'account_id' => $account->id,
                'date_format' => $dateFormat,
                'amount_locale' => $amountLocale,
                'rows_total' => $preview['counts']['total'],
                'imported' => $result['imported'],
                'skipped_duplicates' => $result['skipped_duplicates'],
                'skipped_invalid' => $result['skipped_invalid'],
                'skipped_by_user' => $result['skipped_by_user'],
                'income_cents' => $income,
                'expense_cents' => $expense,
                'currency' => $account->currency,
            ], 'import:'.$batch, $account->currency);
        });

        $this->discard($user, $token);

        return $result;
    }
}
