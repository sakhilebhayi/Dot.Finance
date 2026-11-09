<?php

namespace App\Services\Tax;

use App\Models\Shared\Attachment;
use App\Support\Ledger\LedgerContext;
use Illuminate\Support\Collection;

/**
 * Whether a journal entry's source document has a file attached. Attachments hang off the source record (a bill, a
 * transaction, a claim, or the journal entry itself); this matches them by the source id and the record's class name,
 * inside the ledger only. It answers "is there a file", never "is the file a valid tax invoice".
 */
final class SupportingDocuments
{
    private const TYPES = [
        'transaction' => ['transaction'],
        'bill' => ['bill'],
        'expense_claim' => ['expenseclaim', 'claim'],
        'claim' => ['expenseclaim', 'claim'],
        'payment' => ['payment'],
        'invoice' => ['salesdocument', 'invoice'],
        'credit_note' => ['salesdocument', 'creditnote'],
    ];

    /**
     * @param  Collection<int, object>  $entries  rows with entry_id, source_type, source_id
     * @return array<int, bool> entry_id => has a file
     */
    public function forEntries(LedgerContext $ctx, Collection $entries): array
    {
        if ($entries->isEmpty()) {
            return [];
        }

        $sourceIds = $entries->pluck('source_id')->filter(fn ($v) => ctype_digit((string) $v))->map(fn ($v) => (int) $v)->unique()->all();
        $entryIds = $entries->pluck('entry_id')->unique()->all();

        $attachments = Attachment::query()->forContext($ctx)->whereNull('archived_at')
            ->where(fn ($q) => $q->whereIn('attachable_id', array_merge($sourceIds, $entryIds)))
            ->get(['attachable_type', 'attachable_id']);

        $has = [];
        foreach ($entries as $e) {
            $has[(int) $e->entry_id] = false;
            $names = self::TYPES[$e->source_type] ?? [];
            foreach ($attachments as $a) {
                $base = strtolower(class_basename((string) $a->attachable_type));
                if ($base === 'journalentry' && (int) $a->attachable_id === (int) $e->entry_id) {
                    $has[(int) $e->entry_id] = true;
                    break;
                }
                if ((int) $a->attachable_id === (int) $e->source_id && $names !== [] && in_array($base, $names, true)) {
                    $has[(int) $e->entry_id] = true;
                    break;
                }
            }
        }

        return $has;
    }
}
