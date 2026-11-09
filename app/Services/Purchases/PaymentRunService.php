<?php

namespace App\Services\Purchases;

use App\Models\Account;
use App\Models\Controls\ApprovalRequest;
use App\Models\Purchases\Bill;
use App\Models\Purchases\ExpenseClaim;
use App\Models\Purchases\PaymentRun;
use App\Models\Purchases\PaymentRunItem;
use App\Models\Purchases\SupplierBankDetail;
use App\Models\Shared\Party;
use App\Models\User;
use App\Services\Controls\ApprovalService;
use App\Support\FinanceAudit;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A payment run pays everything that falls due by a date in one go:
 *
 *   candidates()   the open bills and claims due by the date, in the chosen account's currency (base-currency accounts only),
 *                  by supplier. A supplier with a bank-detail change waiting for approval is held out and said so.
 *   create()       the run as a draft, idempotent on its key (the form sends one, so a double click makes one run)
 *   submit()       asks for approval when a payment policy covers the total, else leaves it ready
 *   execute()      pays every supplier (one payment each, through SupplierPayments) in ONE transaction: all of it or none of it.
 *                  Running it again does nothing.
 *   csv()          a generic CSV of the payments. It is not any bank's file format; map the columns in your bank's import tool.
 *                  Only suppliers with approved bank details are in it; the rest are listed in `skipped`.
 */
final class PaymentRunService
{
    public function __construct(
        private readonly SupplierPayments $payments,
        private readonly SupplierBankDetails $bank,
        private readonly PurchaseLedger $ledger,
        private readonly BillStatus $status,
        private readonly ApprovalService $approvals,
    ) {}

    /**
     * @return Collection<int, array{party: Party, items: list<array{type: string, id: int, number: string, due: ?string, open: int}>, total: int, held: ?string}>
     */
    public function candidates(LedgerContext $context, CarbonImmutable $dueBy, string $currency): Collection
    {
        return LedgerContext::run($context, function () use ($dueBy, $currency) {
            $groups = [];

            Bill::query()->with('party')->where('kind', 'bill')->whereIn('status', ['open', 'partially_paid'])->where('currency', $currency)
                ->whereNotNull('due_date')->where('due_date', '<=', $dueBy->toDateString())->orderBy('due_date')->get()
                ->each(function (Bill $bill) use (&$groups) {
                    $open = $this->status->openCents($bill);
                    if ($open > 0) {
                        $groups[$bill->party_id]['items'][] = ['type' => 'bill', 'id' => $bill->id, 'number' => $bill->displayNumber(), 'due' => $bill->due_date->toDateString(), 'open' => $open];
                        $groups[$bill->party_id]['party'] = $bill->party;
                    }
                });

            ExpenseClaim::query()->with('party')->whereIn('status', ['open', 'partially_paid'])->where('currency', $currency)->where('claim_date', '<=', $dueBy->toDateString())->get()
                ->each(function (ExpenseClaim $claim) use (&$groups) {
                    $open = $this->status->openCents($claim);
                    if ($open > 0) {
                        $groups[$claim->party_id]['items'][] = ['type' => 'expense_claim', 'id' => $claim->id, 'number' => $claim->displayNumber(), 'due' => $claim->claim_date->toDateString(), 'open' => $open];
                        $groups[$claim->party_id]['party'] = $claim->party;
                    }
                });

            return collect($groups)->map(function (array $g) {
                $party = $g['party'];
                $pending = $this->bank->pending($party);

                return [
                    'party' => $party, 'items' => $g['items'], 'total' => array_sum(array_column($g['items'], 'open')),
                    'held' => $pending !== null ? 'A change to their bank details is waiting for approval, so they are held out of the run.' : null,
                ];
            })->sortBy(fn ($g) => strtolower($g['party']->name))->values();
        });
    }

    /**
     * @param  array{name: string, due_by: string, paid_on: string, cash_account_id: int, idempotency_key: string, selection: list<array{type: string, id: int, amount_cents?: int}>}  $data
     *
     * @throws PurchasesException
     */
    public function create(LedgerContext $context, User $by, array $data): PaymentRun
    {
        return LedgerContext::run($context, function () use ($context, $by, $data) {
            $key = trim((string) ($data['idempotency_key'] ?? ''));
            if ($key === '' || strlen($key) > 80) {
                throw new PurchasesException('A payment run needs a key so that sending the form twice makes one run.', 'key_required');
            }

            $existing = PaymentRun::query()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                return $existing;
            }

            $settings = $this->ledger->requireAccounting($context);
            $account = Account::query()->find((int) ($data['cash_account_id'] ?? 0)) ?? throw new PurchasesException('Choose the account the run pays from.', 'bad_account');
            if (strtoupper($account->currency) !== $settings->base_currency) {
                throw new PurchasesException('Payment runs pay from an account in the ledger\'s own currency. Pay foreign-currency bills one by one.', 'foreign_run');
            }

            $dueBy = CarbonImmutable::parse($data['due_by']);
            $candidates = $this->candidates($context, $dueBy, $account->currency)->keyBy(fn ($g) => $g['party']->id);

            $rows = [];
            foreach ($data['selection'] ?? [] as $pick) {
                $found = null;
                foreach ($candidates as $group) {
                    foreach ($group['items'] as $item) {
                        if ($item['type'] === $pick['type'] && $item['id'] === (int) $pick['id']) {
                            $found = [$group, $item];
                        }
                    }
                }
                if ($found === null) {
                    throw new PurchasesException('One of the bills chosen is not due by that date, is already paid, or is not in this currency.', 'not_a_candidate');
                }
                [$group, $item] = $found;
                if ($group['held'] !== null) {
                    throw new PurchasesException($group['party']->name.': '.$group['held'], 'held');
                }
                $amount = (int) ($pick['amount_cents'] ?? $item['open']);
                if ($amount <= 0 || $amount > $item['open']) {
                    throw new PurchasesException($item['number'].': pay between 0.01 and what is still owed.', 'bad_amount');
                }
                $rows[] = ['party' => $group['party'], 'type' => $item['type'], 'id' => $item['id'], 'amount' => $amount];
            }

            if ($rows === []) {
                throw new PurchasesException('Choose at least one bill to pay.', 'empty');
            }

            try {
                return DB::transaction(function () use ($context, $by, $data, $account, $rows, $key) {
                    $run = PaymentRun::query()->create($context->ownerAttributes() + [
                        'name' => mb_substr(trim((string) ($data['name'] ?? '')) ?: 'Payment run '.CarbonImmutable::parse($data['paid_on'])->toDateString(), 0, 120),
                        'due_by' => $data['due_by'], 'paid_on' => $data['paid_on'], 'cash_account_id' => $account->id, 'currency' => $account->currency, 'status' => 'draft',
                        'idempotency_key' => $key, 'total_cents' => array_sum(array_column($rows, 'amount')),
                        'supplier_count' => count(array_unique(array_map(fn ($r) => $r['party']->id, $rows))), 'created_by' => $by->id,
                    ]);

                    foreach ($rows as $r) {
                        PaymentRunItem::query()->create($context->ownerAttributes() + [
                            'payment_run_id' => $run->id, 'party_id' => $r['party']->id, 'allocatable_type' => $r['type'], 'allocatable_id' => $r['id'], 'amount_cents' => $r['amount'],
                        ]);
                    }

                    return $run;
                });
            } catch (UniqueConstraintViolationException) {
                return PaymentRun::query()->where('idempotency_key', $key)->firstOrFail();
            }
        });
    }

    /** Ask for approval when a payment policy covers the run's total. Returns the run (awaiting approval, or still draft and ready). */
    public function submit(User $by, PaymentRun $run): PaymentRun
    {
        return LedgerContext::run($run->ledgerContext(), fn () => DB::transaction(function () use ($by, $run) {
            $locked = PaymentRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'draft') {
                return $locked;
            }

            if (ApprovalService::requiresApproval('payment', $locked->total_cents, $locked->currency, $locked->ledgerContext())) {
                $locked->forceFill(['status' => 'awaiting_approval'])->save();
                $this->approvals->request($by, $locked);
            }

            return $locked->refresh();
        }));
    }

    public function approvalDecided(PaymentRun $run, ApprovalRequest $request): void
    {
        LedgerContext::run($run->ledgerContext(), function () use ($run, $request) {
            $locked = PaymentRun::query()->whereKey($run->getKey())->lockForUpdate()->first();
            if ($locked === null || $locked->status !== 'awaiting_approval') {
                return;
            }
            $locked->forceFill(['status' => $request->status === ApprovalRequest::APPROVED ? 'approved' : 'draft'])->save();
        });
    }

    /** Pay it. All or nothing; a second call returns the executed run. */
    public function execute(User $by, PaymentRun $run): PaymentRun
    {
        $context = $run->ledgerContext();

        return LedgerContext::run($context, fn () => DB::transaction(function () use ($by, $run, $context) {
            $locked = PaymentRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === 'executed') {
                return $locked;
            }
            if ($locked->status === 'awaiting_approval') {
                throw new PurchasesException('This run is waiting for approval.', 'awaiting_approval');
            }
            if ($locked->status === 'cancelled') {
                throw new PurchasesException('This run was cancelled.', 'cancelled');
            }

            $needsApproval = ApprovalService::requiresApproval('payment', $locked->total_cents, $locked->currency, $context);
            if ($needsApproval && ! $this->approvals->isApproved($locked)) {
                throw new PurchasesException('Payments of this size need approval. Submit the run for approval first.', 'not_approved');
            }

            $items = PaymentRunItem::query()->where('payment_run_id', $locked->id)->orderBy('party_id')->orderBy('allocatable_type')->orderBy('allocatable_id')->get();

            foreach ($items->groupBy('party_id') as $partyId => $group) {
                if (SupplierBankDetail::query()->where('party_id', $partyId)->where('status', 'pending')->exists()) {
                    throw new PurchasesException('A supplier in this run has a bank-detail change waiting for approval. Take them out of the run or approve the change first.', 'held');
                }

                $payment = $this->payments->pay($context, $by, [
                    'party_id' => (int) $partyId, 'cash_account_id' => $locked->cash_account_id, 'paid_on' => $locked->paid_on->toDateString(), 'method' => 'eft',
                    'reference' => mb_substr('RUN-'.$locked->id, 0, 100), 'idempotency_key' => 'run-'.$locked->id.'-party-'.$partyId,
                    'allocations' => $group->map(fn (PaymentRunItem $i) => ['type' => $i->allocatable_type, 'id' => $i->allocatable_id, 'amount_cents' => $i->amount_cents])->values()->all(),
                ], approved: true);

                PaymentRunItem::query()->whereIn('id', $group->pluck('id'))->update(['payment_id' => $payment->id]);
            }

            $locked->forceFill(['status' => 'executed', 'executed_at' => now(), 'executed_by' => $by->id])->save();

            return $locked;
        }));
    }

    public function cancel(User $by, PaymentRun $run): PaymentRun
    {
        return LedgerContext::run($run->ledgerContext(), fn () => DB::transaction(function () use ($by, $run) {
            $locked = PaymentRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === 'executed') {
                throw new PurchasesException('This run has been paid. Reverse the payments instead.', 'executed');
            }
            if ($locked->status === 'cancelled') {
                return $locked;
            }

            $pending = $locked->status === 'awaiting_approval' ? $this->approvals->latestFor($locked) : null;
            $locked->forceFill(['status' => 'cancelled'])->save();
            if ($pending?->isPending()) {
                $this->approvals->cancel($by, $pending, 'The run was cancelled');
            }

            return $locked;
        }));
    }

    /**
     * A generic CSV of the run. One row per supplier payment with approved bank details; a supplier without them is in `skipped`.
     * Cells that begin with = + - @ are prefixed so a spreadsheet does not run them as formulas. Writing the file is recorded in the audit log.
     *
     * @return array{csv: string, rows: int, skipped: list<string>}
     */
    public function csv(User $by, PaymentRun $run): array
    {
        return LedgerContext::run($run->ledgerContext(), function () use ($by, $run) {
            $out = fopen('php://temp', 'r+');
            fputcsv($out, ['Beneficiary', 'Bank', 'Account number', 'Branch code', 'Account type', 'SWIFT', 'IBAN', 'Amount', 'Currency', 'Your reference', 'Beneficiary reference', 'Pay on']);

            $skipped = [];
            $rows = 0;

            foreach (PaymentRunItem::query()->where('payment_run_id', $run->id)->get()->groupBy('party_id') as $partyId => $group) {
                $party = Party::query()->withTrashed()->find($partyId);
                $detail = $party ? $this->bank->active($party) : null;

                if ($detail === null) {
                    $skipped[] = ($party?->name ?? 'Supplier #'.$partyId).': no approved bank details on file';

                    continue;
                }

                $d = $this->bank->reveal($detail);
                $numbers = Bill::query()->whereIn('id', $group->where('allocatable_type', 'bill')->pluck('allocatable_id'))->pluck('supplier_reference', 'id');
                fputcsv($out, array_map([$this, 'safe'], [
                    $d['account_holder'] ?? $party->name, $d['bank_name'] ?? '', $d['account_number'] ?? '', $d['branch_code'] ?? '', $d['account_type'] ?? '', $d['swift'] ?? '', $d['iban'] ?? '',
                    Money::toDecimalString($group->sum('amount_cents')), $run->currency, 'RUN-'.$run->id, mb_substr($numbers->filter()->implode(' '), 0, 40), $run->paid_on->toDateString(),
                ]));
                $rows++;
            }

            rewind($out);
            $csv = (string) stream_get_contents($out);
            fclose($out);

            $run->forceFill(['csv_exported_at' => now()])->save();
            FinanceAudit::record($run->ledgerOwner(), 'exported', 'payment_run', $run->id, 'Payment run #'.$run->id, ['rows' => ['after' => $rows], 'skipped' => ['after' => count($skipped)]], null, $run->currency, $by->id);

            return ['csv' => $csv, 'rows' => $rows, 'skipped' => $skipped];
        });
    }

    public function safe(mixed $cell): string
    {
        $cell = (string) $cell;

        return $cell !== '' && in_array($cell[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$cell : $cell;
    }
}
