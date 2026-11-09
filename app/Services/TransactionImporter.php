<?php

namespace App\Services;

use App\Exceptions\ImportInterrupted;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\GlSync;
use App\Support\FinanceAudit;
use App\Support\ImportDates;
use App\Support\ImportFingerprint;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

    /** Rows written per database transaction. */
    public const CHUNK_ROWS = 500;

    /** The command line has no web time limit, so it lifts the row cap. */
    private bool $unlimited = false;

    public function __construct(private readonly CsvTransactionParser $parser) {}

    public function withoutRowLimit(): static
    {
        $this->unlimited = true;

        return $this;
    }

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

        // Pass 1 reads only the raw dates and amounts (distinct values, to detect the formats); pass 2 interprets the rows one at a
        // time. Neither holds the file: a 5 MB upload (about 90,000 rows) costs the same few megabytes as a 50-row one.
        $file = $this->parser->stream($staged['path']);
        $dates = [];
        $amounts = [];
        foreach ($file['rows'] as $raw) {
            if ($raw['raw_date'] !== null) {
                $dates[$raw['raw_date']] = true;
            }
            if ($raw['raw_amount'] !== null) {
                $amounts[$raw['raw_amount']] = true;
            }
        }
        $detectedDate = ImportDates::detect(array_map('strval', array_keys($dates)));
        $detectedAmount = Money::detectLocale(array_map('strval', array_keys($amounts)));
        unset($dates, $amounts);

        $dateFormat = in_array($dateFormat, ImportDates::FORMATS, true) ? $dateFormat : $detectedDate['format'];
        $amountLocale = in_array($amountLocale, ['en', 'eu'], true) ? $amountLocale : $detectedAmount['locale'];
        $defaultCategory = $defaultCategoryId ? Category::query()->forContext($context)->find($defaultCategoryId) : null;

        $file = $this->parser->stream($staged['path']);
        $kept = [];
        $counts = ['total' => 0, 'invalid' => 0, 'duplicates' => 0, 'importable' => 0];
        foreach ($this->interpreted($file['rows'], $account, $context, $dateFormat, $amountLocale, $defaultCategory) as $row) {
            $counts['total']++;
            if ($row['error'] !== null) {
                $counts['invalid']++;
            } elseif ($row['duplicate']) {
                $counts['duplicates']++;
            } elseif ($row['date'] !== null && $row['cents'] !== null) {
                $counts['importable']++;
            }
            if (count($kept) < self::PREVIEW_ROWS) {
                $kept[] = $row;
            }
        }

        $columnsOk = $file['columns_found']['date'] && $file['columns_found']['description'] && $file['columns_found']['amount'];
        $tooLarge = $counts['total'] > (int) config('ops.import_max_rows', 5000) && ! $this->unlimited;
        $ready = $columnsOk && $dateFormat !== null && $amountLocale !== null && ! $tooLarge;

        return [
            'token' => $token,
            'too_large' => $tooLarge,
            'max_rows' => (int) config('ops.import_max_rows', 5000),
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
            // only the first PREVIEW_ROWS rows are kept for the page; the counts cover the whole file
            'rows' => $kept,
            'counts' => $counts,
            'ready' => $ready,
        ];
    }

    /**
     * Interpret the raw rows one by one and mark the ones already imported, 500 rows at a time (one lookup per 500, not per row).
     *
     * @param  iterable<int, array<string, mixed>>  $raws
     * @return \Generator<int, array<string, mixed>>
     */
    private function interpreted(iterable $raws, Account $account, LedgerContext $context, ?string $dateFormat, ?string $amountLocale, ?Category $defaultCategory): \Generator
    {
        $categories = Category::query()->forContext($context)->get()->keyBy(fn (Category $c) => mb_strtolower($c->name));
        $seen = [];
        $chunk = [];

        $release = function () use (&$chunk, $account): array {
            $fingerprints = array_values(array_filter(array_column($chunk, 'fingerprint')));
            $existing = [];
            if ($fingerprints !== []) {
                foreach (Transaction::query()->forContext($account->ledgerContext())->whereIn('import_fingerprint', $fingerprints)->pluck('import_fingerprint') as $found) {
                    $existing[$found] = true;
                }
            }
            foreach ($chunk as &$row) {
                $row['duplicate'] = $row['fingerprint'] !== null && isset($existing[$row['fingerprint']]);
            }
            unset($row);
            $done = $chunk;
            $chunk = [];

            return $done;
        };

        foreach ($raws as $raw) {
            $row = $this->interpret($raw, $dateFormat, $amountLocale, $categories, $defaultCategory);
            if ($row['error'] === null && $row['date'] !== null && $row['cents'] !== null) {
                // the n-th identical row in a file gets its own fingerprint, so two real purchases of the same amount on one day both import
                $key = md5($row['date'].'|'.$row['cents'].'|'.ImportFingerprint::normalise((string) $row['description']), true);
                $seen[$key] = ($seen[$key] ?? 0) + 1;
                $row['fingerprint'] = ImportFingerprint::make($account->id, $row['date'], $row['cents'], (string) $row['description'], $seen[$key]);
            }
            $chunk[] = $row;
            if (count($chunk) >= 500) {
                yield from $release();
            }
        }
        yield from $release();
    }

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

    // ── step 3 ────────────────────────────────────────────────────────────

    /**
     * @param  list<int>  $skipLines  file line numbers the user unticked
     * @return array{imported: int, skipped_duplicates: int, skipped_invalid: int, skipped_by_user: int}
     */
    public function commit(User $user, LedgerContext $context, string $token, string $dateFormat, string $amountLocale, ?int $defaultCategoryId, array $skipLines): array
    {
        $staged = $this->load($user, $context, $token) ?? throw new InvalidArgumentException('Unknown import.');
        $account = Account::query()->forContext($context)->findOrFail($staged['account_id']);
        $defaultCategory = $defaultCategoryId ? Category::query()->forContext($context)->find($defaultCategoryId) : null;
        // The page refuses a file over the row limit, but the form can be posted without the page: the limit is enforced here too.
        if (! $this->unlimited) {
            $limit = (int) config('ops.import_max_rows', 5000);
            $n = 0;
            foreach ($this->parser->stream($staged['path'])['rows'] as $ignored) {
                if (++$n > $limit) {
                    throw ValidationException::withMessages(['token' => 'This file has more than '.number_format($limit).' rows, which is the most one import through the page may hold. Split it, or ask whoever runs the server to import it from the command line.']);
                }
            }
        }
        $rows = $this->parser->stream($staged['path'])['rows'];

        $batch = (string) Str::uuid();
        $skip = array_flip($skipLines);
        $result = ['imported' => 0, 'skipped_duplicates' => 0, 'skipped_invalid' => 0, 'skipped_by_user' => 0];
        $total = 0;
        $income = 0;
        $expense = 0;
        $pending = [];

        // Rows are read from the file one at a time and written CHUNK_ROWS at a time, each chunk in its own database transaction:
        // memory does not grow with the file (one transaction holding tens of thousands of nested postings did not fit in
        // 128 MB), and a failure part way leaves whole chunks saved, never half a chunk. A file of up to CHUNK_ROWS rows is
        // still all or nothing, with its audit record in the same transaction.
        $audit = function () use ($account, $staged, $dateFormat, $amountLocale, $batch, &$result, &$total, &$income, &$expense) {
            FinanceAudit::record($account->ledgerOwner(), 'imported', 'import', null, $staged['filename'], [
                'file' => $staged['filename'],
                'account_id' => $account->id,
                'date_format' => $dateFormat,
                'amount_locale' => $amountLocale,
                'rows_total' => $total,
                'imported' => $result['imported'],
                'skipped_duplicates' => $result['skipped_duplicates'],
                'skipped_invalid' => $result['skipped_invalid'],
                'skipped_by_user' => $result['skipped_by_user'],
                'income_cents' => $income,
                'expense_cents' => $expense,
                'currency' => $account->currency,
            ], 'import:'.$batch, $account->currency);
        };

        $write = function (array $rows, bool $last) use ($account, $batch, $audit, &$result, &$income, &$expense) {
            DB::transaction(function () use ($rows, $last, $account, $batch, $audit, &$result, &$income, &$expense) {
                FinanceAudit::withoutRowLogging(function () use ($rows, $account, $batch, &$result, &$income, &$expense) {
                    foreach ($rows as $row) {
                        $created = Transaction::create($account->ledgerOwner() + [
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
                        // With accounting on, each imported row is journalled inside this same transaction.
                        app(GlSync::class)->transaction($created);
                        $row['type'] === 'income' ? $income += $row['cents'] : $expense += abs($row['cents']);
                    }
                });
                $result['imported'] += count($rows);
                if ($last) {
                    $audit();
                }
            });
        };

        $saved = 0;
        try {
            foreach ($this->interpreted($rows, $account, $context, $dateFormat, $amountLocale, $defaultCategory) as $row) {
                $total++;
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
                $pending[] = $row;
                if (count($pending) >= self::CHUNK_ROWS) {
                    $write($pending, false);
                    $saved = $result['imported'];
                    $pending = [];
                }
            }
            $write($pending, true);
        } catch (\Throwable $e) {
            // a chunk that threw was rolled back whole; the counters above only moved for chunks that committed
            throw $saved > 0 ? new ImportInterrupted($saved, $e) : $e;
        }

        $this->discard($user, $token);

        return $result;
    }
}
