<?php

namespace App\Services\Purchases;

use App\Enums\Capability;
use App\Models\Purchases\SupplierBankDetail;
use App\Models\Shared\Party;
use App\Models\User;
use App\Support\FinanceAudit;
use App\Support\Ledger\LedgerAccess;
use App\Support\Ledger\LedgerContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Supplier bank details, treated as the fraud vector they are.
 *
 *  - The details are encrypted at rest and never shown in full: the screens show the bank and the last four digits.
 *  - A change is never an edit. It is a NEW version that stays "pending" until a person who may approve payments (and is not the person
 *    who asked for the change) activates it. The previous version is kept as "superseded". Only in a ledger with a single member, where there is
 *    nobody else, may the same person approve; that is recorded on the row as self-approved.
 *  - The very first set of details is activated at once when the person entering it may approve payments (recorded the same way);
 *    otherwise it waits like any change.
 *  - Every request, approval and rejection is written to the audit log (without the account number).
 *  - A supplier whose details have a change waiting is held out of payment runs.
 *  - The same account number turning up on two suppliers is reported, because that is how a fake supplier is usually spotted.
 */
final class SupplierBankDetails
{
    /**
     * @param  array{bank_name: string, account_holder: string, account_number: string, branch_code?: ?string, account_type?: ?string, swift?: ?string, iban?: ?string}  $details
     *
     * @throws PurchasesException
     */
    public function request(LedgerContext $context, User $by, Party $party, array $details, string $reason, bool $verifiedByCallback = false): SupplierBankDetail
    {
        $clean = $this->validate($details);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new PurchasesException('Say why the bank details are being set or changed, and how you checked them (for example "called the supplier on the number on file").', 'reason_required');
        }

        return LedgerContext::run($context, fn () => DB::transaction(function () use ($context, $by, $party, $clean, $reason, $verifiedByCallback) {
            $party = Party::query()->whereKey($party->getKey())->lockForUpdate()->firstOrFail();

            if (SupplierBankDetail::query()->where('party_id', $party->id)->where('status', 'pending')->exists()) {
                throw new PurchasesException('A change to this supplier\'s bank details is already waiting for approval. Approve or reject it first.', 'pending_exists');
            }

            $active = $this->active($party);
            $version = (int) SupplierBankDetail::query()->where('party_id', $party->id)->max('version') + 1;

            $row = SupplierBankDetail::query()->create($context->ownerAttributes() + [
                'party_id' => $party->id, 'version' => $version, 'status' => 'pending', 'payload' => $clean,
                'bank_name' => mb_substr($clean['bank_name'], 0, 120), 'account_last4' => substr($clean['account_number'], -4),
                'account_hash' => $this->hash($clean['account_number']), 'change_reason' => mb_substr($reason, 0, 500),
                'verified_by_callback' => $verifiedByCallback, 'requested_by' => $by->id, 'requested_at' => now(),
            ]);

            $this->audit($row, $by, 'bank_details_requested', ['version' => ['after' => $version], 'verified_by_callback' => ['after' => $verifiedByCallback], 'replaces_active' => ['after' => $active !== null]]);

            // the first set of details, entered by someone who may approve payments, needs no second person (there is nothing to be changed from)
            if ($active === null && LedgerAccess::for($by, $context)?->can(Capability::ApprovePayment)) {
                return $this->activate($row, $by, 'First bank details entered by a person who may approve payments.', selfApproved: true);
            }

            return $row;
        }));
    }

    /** @throws PurchasesException */
    public function approve(User $by, SupplierBankDetail $detail, ?string $note = null): SupplierBankDetail
    {
        return LedgerContext::run($detail->ledgerContext(), fn () => DB::transaction(function () use ($by, $detail, $note) {
            $locked = SupplierBankDetail::query()->whereKey($detail->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isActive()) {
                return $locked; // approved already: idempotent
            }
            if (! $locked->isPending()) {
                throw new PurchasesException('Only a pending change can be approved.', 'not_pending');
            }

            $context = $locked->ledgerContext();
            if (! (LedgerAccess::for($by, $context)?->can(Capability::ApprovePayment) ?? false)) {
                throw new PurchasesException('Only someone who may approve payments can approve a change to bank details.', 'missing_capability');
            }

            $own = $locked->requested_by === $by->id;
            if ($own && ! $this->isSoleMember($context)) {
                throw new PurchasesException('You asked for this change, so someone else who may approve payments has to approve it.', 'separation_of_duties');
            }

            return $this->activate($locked, $by, $note, selfApproved: $own);
        }));
    }

    /** @throws PurchasesException */
    public function reject(User $by, SupplierBankDetail $detail, string $note): SupplierBankDetail
    {
        $note = trim($note);
        if ($note === '') {
            throw new PurchasesException('Say why you are rejecting the change.', 'reason_required');
        }

        return LedgerContext::run($detail->ledgerContext(), fn () => DB::transaction(function () use ($by, $detail, $note) {
            $locked = SupplierBankDetail::query()->whereKey($detail->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === 'rejected') {
                return $locked;
            }
            if (! $locked->isPending()) {
                throw new PurchasesException('Only a pending change can be rejected.', 'not_pending');
            }
            if (! (LedgerAccess::for($by, $locked->ledgerContext())?->can(Capability::ApprovePayment) ?? false)) {
                throw new PurchasesException('Only someone who may approve payments can reject a change to bank details.', 'missing_capability');
            }

            $locked->forceFill(['status' => 'rejected', 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => mb_substr($note, 0, 500)])->save();
            $this->audit($locked, $by, 'bank_details_rejected', ['version' => ['after' => $locked->version], 'status' => ['after' => 'rejected']]);

            return $locked;
        }));
    }

    public function active(Party $party): ?SupplierBankDetail
    {
        return LedgerContext::run($party->ledgerContext(), fn () => SupplierBankDetail::query()->where('party_id', $party->id)->where('status', 'active')->first());
    }

    public function pending(Party $party): ?SupplierBankDetail
    {
        return LedgerContext::run($party->ledgerContext(), fn () => SupplierBankDetail::query()->where('party_id', $party->id)->where('status', 'pending')->first());
    }

    /** Every version, newest first (the change history). @return Collection<int, SupplierBankDetail> */
    public function history(Party $party): Collection
    {
        return LedgerContext::run($party->ledgerContext(), fn () => SupplierBankDetail::query()->where('party_id', $party->id)->with(['requester:id,name', 'decider:id,name'])->orderByDesc('version')->get());
    }

    /** Other suppliers that have (or have asked for) the same account number: a reason to look twice. @return Collection<int, Party> */
    public function sharedWith(SupplierBankDetail $detail): Collection
    {
        return LedgerContext::run($detail->ledgerContext(), function () use ($detail) {
            $ids = SupplierBankDetail::query()->where('account_hash', $detail->account_hash)->where('party_id', '!=', $detail->party_id)
                ->whereIn('status', ['active', 'pending'])->pluck('party_id')->unique();

            return Party::query()->withTrashed()->whereIn('id', $ids)->get();
        });
    }

    /** The full details, for the one place that needs them (a payment file). The caller must record that it did. */
    public function reveal(SupplierBankDetail $detail): array
    {
        return (array) $detail->payload;
    }

    public function isSoleMember(LedgerContext $context): bool
    {
        return $context->isPersonal() || (($context->team()?->allUsers()->count() ?? 0) <= 1);
    }

    private function activate(SupplierBankDetail $row, User $by, ?string $note, bool $selfApproved): SupplierBankDetail
    {
        SupplierBankDetail::query()->where('party_id', $row->party_id)->where('status', 'active')->whereKeyNot($row->id)->get()
            ->each(fn (SupplierBankDetail $old) => $old->forceFill(['status' => 'superseded'])->save());

        $row->forceFill([
            'status' => 'active', 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $note !== null ? mb_substr($note, 0, 500) : null,
            'self_approved' => $selfApproved, 'activated_at' => now(),
        ])->save();

        $this->audit($row, $by, 'bank_details_activated', ['version' => ['after' => $row->version], 'status' => ['after' => 'active'], 'self_approved' => ['after' => $selfApproved]]);

        return $row;
    }

    /** @param array<string, mixed> $details @return array<string, string|null> */
    private function validate(array $details): array
    {
        $bank = trim((string) ($details['bank_name'] ?? ''));
        $holder = trim((string) ($details['account_holder'] ?? ''));
        $number = preg_replace('/[\s-]+/', '', trim((string) ($details['account_number'] ?? ''))) ?? '';
        $branch = preg_replace('/[\s-]+/', '', trim((string) ($details['branch_code'] ?? ''))) ?? '';
        $iban = strtoupper(preg_replace('/\s+/', '', trim((string) ($details['iban'] ?? ''))) ?? '');
        $swift = strtoupper(preg_replace('/\s+/', '', trim((string) ($details['swift'] ?? ''))) ?? '');
        $type = trim((string) ($details['account_type'] ?? ''));

        if ($bank === '' || $holder === '') {
            throw new PurchasesException('Enter the bank and the name on the account.', 'incomplete');
        }
        if (preg_match('/^[0-9]{4,20}$/', $number) !== 1) {
            throw new PurchasesException('An account number is 4 to 20 digits.', 'bad_account_number');
        }
        if ($branch !== '' && preg_match('/^[0-9]{4,10}$/', $branch) !== 1) {
            throw new PurchasesException('A branch code is digits only.', 'bad_branch');
        }
        if ($iban !== '' && preg_match('/^[A-Z]{2}[0-9A-Z]{13,32}$/', $iban) !== 1) {
            throw new PurchasesException('That does not look like an IBAN.', 'bad_iban');
        }
        if ($swift !== '' && preg_match('/^[A-Z0-9]{8}([A-Z0-9]{3})?$/', $swift) !== 1) {
            throw new PurchasesException('A SWIFT/BIC code is 8 or 11 letters and digits.', 'bad_swift');
        }

        return [
            'bank_name' => mb_substr($bank, 0, 120), 'account_holder' => mb_substr($holder, 0, 120), 'account_number' => $number,
            'branch_code' => $branch !== '' ? $branch : null, 'account_type' => $type !== '' ? mb_substr($type, 0, 30) : null,
            'swift' => $swift !== '' ? $swift : null, 'iban' => $iban !== '' ? $iban : null,
        ];
    }

    private function hash(string $accountNumber): string
    {
        return hash_hmac('sha256', $accountNumber, (string) config('app.key'));
    }

    /** @param array<string, array<string, mixed>> $changes */
    private function audit(SupplierBankDetail $row, User $by, string $action, array $changes): void
    {
        FinanceAudit::record($row->ledgerOwner(), $action, 'supplier_bank', $row->id, 'Bank details v'.$row->version.' ending '.$row->account_last4, $changes, null, null, $by->id);
    }
}
