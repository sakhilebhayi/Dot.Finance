<?php

namespace App\Services\Purchases;

use App\Models\Purchases\Bill;
use App\Models\Purchases\ExpenseClaim;
use App\Services\Shared\PaymentAllocator;
use App\Support\Ledger\LedgerContext;
use Illuminate\Support\Facades\DB;

/** Open balance and payment status of a bill or claim. Status follows allocations, never the other way round. */
final class BillStatus
{
    public function __construct(private readonly PaymentAllocator $allocator) {}

    /** What is still owed, in the document's currency: total, less posted credit notes, less live allocations of recorded payments. */
    public function openCents(Bill|ExpenseClaim $doc): int
    {
        if (! $doc->isPosted()) {
            return 0;
        }

        return max(0, $doc->allocatableTotalCents() - $this->allocator->allocatedTo($doc));
    }

    public function allocatedCents(Bill|ExpenseClaim $doc): int
    {
        return $this->allocator->allocatedTo($doc);
    }

    /** Move a posted bill or claim between open / partially_paid / paid to match its allocations. Returns the new status. */
    public function refresh(Bill|ExpenseClaim $doc): string
    {
        return LedgerContext::run($doc->ledgerContext(), fn () => DB::transaction(function () use ($doc) {
            $locked = $doc::query()->whereKey($doc->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isPosted()) {
                return $locked->status;
            }

            $open = $this->openCents($locked);
            $allocated = $this->allocator->allocatedTo($locked);
            $status = $open <= 0 ? 'paid' : ($allocated > 0 ? 'partially_paid' : 'open');

            if ($status !== $locked->status) {
                $locked->forceFill(['status' => $status])->save();
            }

            $doc->status = $status;

            return $status;
        }));
    }
}
