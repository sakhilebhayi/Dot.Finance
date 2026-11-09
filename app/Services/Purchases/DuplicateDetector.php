<?php

namespace App\Services\Purchases;

use App\Models\Purchases\Bill;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Finds bills that look like ones already in the books. It never decides: a probable duplicate is shown with the reason and
 * the person either fixes it or confirms in writing that it is not (the confirmation is kept on the bill).
 *
 * Reasons:
 *   same_reference           the same supplier already has a bill with this supplier invoice number
 *   same_reference_other     another supplier record has this invoice number and total (the supplier may be on file twice)
 *   same_amount_date         the same supplier has a bill for the same total within a few days (config purchases.duplicates.fuzzy_days)
 *   same_file                (receipts) the same file, by content hash, was uploaded before: see ReceiptCapture
 */
final class DuplicateDetector
{
    /** @return Collection<int, array{bill: Bill, reason: string, label: string}> */
    public function forBill(LedgerContext $context, int $partyId, ?string $reference, int $totalCents, string $currency, CarbonImmutable $date, ?int $excludeId = null, string $kind = 'bill'): Collection
    {
        return LedgerContext::run($context, function () use ($partyId, $reference, $totalCents, $currency, $date, $excludeId, $kind) {
            $norm = self::normalise($reference);
            $days = (int) config('purchases.duplicates.fuzzy_days', 7);
            $found = collect();

            $base = fn () => Bill::query()->where('kind', $kind)->where('status', '!=', 'void')->when($excludeId, fn ($q) => $q->whereKeyNot($excludeId));

            if ($norm !== null) {
                foreach ($base()->where('party_id', $partyId)->where('supplier_reference_norm', $norm)->get() as $bill) {
                    $found->put($bill->id, ['bill' => $bill, 'reason' => 'same_reference', 'label' => 'The same supplier already has '.$bill->displayNumber().' with this invoice number.']);
                }

                foreach ($base()->where('party_id', '!=', $partyId)->where('supplier_reference_norm', $norm)->where('total_cents', $totalCents)->get() as $bill) {
                    $found->put($bill->id, ['bill' => $bill, 'reason' => 'same_reference_other', 'label' => 'Another supplier record ('.$bill->party?->name.') has '.$bill->displayNumber().' with this invoice number and total.']);
                }
            }

            if ($totalCents > 0) {
                $from = $date->subDays($days)->toDateString();
                $to = $date->addDays($days)->toDateString();

                foreach ($base()->where('party_id', $partyId)->where('currency', $currency)->where('total_cents', $totalCents)->where('bill_date', '>=', $from)->where('bill_date', '<=', $to)->get() as $bill) {
                    if (! $found->has($bill->id)) {
                        $found->put($bill->id, ['bill' => $bill, 'reason' => 'same_amount_date', 'label' => $bill->displayNumber().' is for the same amount from the same supplier within '.$days.' days.']);
                    }
                }
            }

            return $found->values();
        });
    }

    /** "inv-0042 / A" and "INV0042A" are the same invoice number. */
    public static function normalise(?string $reference): ?string
    {
        $norm = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $reference) ?? '');

        return $norm === '' ? null : mb_substr($norm, 0, 60);
    }
}
