<?php

namespace App\Services\Shared;

use App\Models\Shared\DocumentSequence;
use App\Support\Ledger\LedgerContext;
use App\Support\Shared\Exceptions\NumberingException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Gap-free document numbers, handed out at ISSUE (not when a draft is created), per ledger and
 * document type, with an optional prefix and an optional yearly restart.
 *
 * How it stays gap-free: next() must be called inside the same database transaction that stores the
 * issued document. It takes the sequence row's lock first (an UPDATE that touches the row, which on
 * PostgreSQL locks the row until commit and on SQLite takes the writer lock), reads the counter,
 * advances it, and returns the number. If the caller's transaction rolls back, the counter rolls back
 * with it and the number is handed out again; if it commits, the number is used. Two requests issuing
 * at once queue on the lock and get consecutive numbers.
 *
 * Format: [prefix-][year-]number padded, e.g. INV-2026-00042, INV-00042 or 00042.
 */
final class Numbering
{
    /**
     * @return string the formatted number, e.g. "INV-2026-00042"
     */
    public function next(string $docType, ?CarbonInterface $on = null, ?LedgerContext $context = null): string
    {
        return $this->issue($docType, $on, $context)['formatted'];
    }

    /**
     * Same, with the parts: the bare counter value, the year it belongs to (null when the sequence
     * does not restart yearly) and the formatted string.
     *
     * @return array{number: int, year: ?int, formatted: string}
     */
    public function issue(string $docType, ?CarbonInterface $on = null, ?LedgerContext $context = null): array
    {
        if (DB::transactionLevel() === 0) {
            throw new NumberingException('Document numbers are only issued inside the transaction that stores the document, so a failure cannot leave a gap. Wrap the issue in DB::transaction().');
        }

        $context ??= LedgerContext::current() ?? throw new NumberingException('No ledger is active, so there is no sequence to number from.');
        $docType = $this->normalise($docType);
        $date = CarbonImmutable::parse(($on ?? now())->toDateString());

        $sequence = $this->lockedSequence($docType, $context);

        $year = $sequence->year_reset ? $date->year : null;

        // A sequence that restarts yearly starts again at 1 the first time a new year is used.
        if ($sequence->year_reset && (int) $sequence->current_year !== $date->year) {
            if ($sequence->current_year !== null && $date->year < (int) $sequence->current_year) {
                throw new NumberingException("The {$docType} sequence has already moved on to {$sequence->current_year}; a number for {$date->year} would break the order. Issue the document in the current year.");
            }

            $number = 1;
        } else {
            $number = (int) $sequence->next_number;
        }

        DocumentSequence::query()->forContext($context)->whereKey($sequence->id)->update([
            'next_number' => $number + 1,
            'current_year' => $sequence->year_reset ? $date->year : $sequence->current_year,
            'last_issued_at' => now(),
        ]);

        return ['number' => $number, 'year' => $year, 'formatted' => $this->format($sequence->prefix, $year, $number, (int) $sequence->padding)];
    }

    /**
     * Set up (or reconfigure) the sequence for a document type. The counter of an existing sequence is
     * never moved by this: only prefix, padding and the yearly restart can change, and only before the
     * first number is issued, because changing the format afterwards would make numbers inconsistent.
     */
    public function configure(string $docType, string $prefix = '', int $padding = 5, bool $yearReset = false, ?LedgerContext $context = null): DocumentSequence
    {
        $context ??= LedgerContext::current() ?? throw new NumberingException('No ledger is active.');
        $docType = $this->normalise($docType);

        return DB::transaction(function () use ($docType, $prefix, $padding, $yearReset, $context) {
            $sequence = $this->lockedSequence($docType, $context, create: false);

            if ($sequence === null) {
                return DocumentSequence::query()->create($context->ownerAttributes() + [
                    'doc_type' => $docType, 'prefix' => $prefix, 'padding' => $padding, 'year_reset' => $yearReset,
                ]);
            }

            if ($sequence->last_issued_at !== null) {
                throw new NumberingException("The {$docType} sequence has already issued numbers; its format can no longer change.");
            }

            DocumentSequence::query()->forContext($context)->whereKey($sequence->id)->update(['prefix' => $prefix, 'padding' => $padding, 'year_reset' => $yearReset]);

            return DocumentSequence::query()->forContext($context)->findOrFail($sequence->id);
        });
    }

    public function format(string $prefix, ?int $year, int $number, int $padding): string
    {
        $parts = array_filter([$prefix !== '' ? $prefix : null, $year !== null ? (string) $year : null], fn ($p) => $p !== null);
        $parts[] = str_pad((string) $number, $padding, '0', STR_PAD_LEFT);

        return implode('-', $parts);
    }

    /** What the next number would look like, without taking it (for a settings screen). Not a reservation. */
    public function peek(string $docType, ?CarbonInterface $on = null, ?LedgerContext $context = null): ?string
    {
        $context ??= LedgerContext::current();
        $sequence = $context ? DocumentSequence::query()->forContext($context)->where('doc_type', $this->normalise($docType))->first() : null;

        if ($sequence === null) {
            return null;
        }

        $date = CarbonImmutable::parse(($on ?? now())->toDateString());
        $year = $sequence->year_reset ? $date->year : null;
        $number = $sequence->year_reset && (int) $sequence->current_year !== $date->year ? 1 : (int) $sequence->next_number;

        return $this->format($sequence->prefix, $year, $number, (int) $sequence->padding);
    }

    private function normalise(string $docType): string
    {
        $docType = strtolower(trim($docType));

        if ($docType === '' || strlen($docType) > 40) {
            throw new NumberingException('A document type is 1 to 40 characters.');
        }

        return $docType;
    }

    /**
     * The sequence row, locked for this transaction. The first statement is an UPDATE that touches the row:
     * that is what takes the lock on both databases. A missing row is created (a concurrent creator
     * collides on the unique index and retries as a reader).
     */
    private function lockedSequence(string $docType, LedgerContext $context, bool $create = true): ?DocumentSequence
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $touched = DocumentSequence::query()->forContext($context)->where('doc_type', $docType)->update(['updated_at' => now()]);

            if ($touched === 0) {
                if (! $create) {
                    return null;
                }

                try {
                    // a savepoint, so a lost race does not poison the caller's transaction on PostgreSQL
                    DB::transaction(fn () => DocumentSequence::query()->create($context->ownerAttributes() + ['doc_type' => $docType]));
                } catch (QueryException) {
                    // someone else created it first: loop and lock theirs
                }

                continue;
            }

            return DocumentSequence::query()->forContext($context)->where('doc_type', $docType)->lockForUpdate()->firstOrFail();
        }

        throw new NumberingException("Could not set up the {$docType} sequence.");
    }
}
