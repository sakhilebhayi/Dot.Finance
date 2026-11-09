<?php

namespace App\Services\Bank;

use App\Exceptions\Bank\ReconciliationRefused;
use App\Models\Account;
use App\Models\Bank\BankAdjustment;
use App\Models\Bank\BankReconciliation;
use App\Models\Bank\BankStatementLine;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\Exceptions\AccountingException;
use App\Services\Accounting\Exceptions\PeriodClosed;
use App\Services\Accounting\GlSync;
use App\Services\Controls\ApprovalService;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Bank charges, interest and authorised variance entries made while reconciling. Each is an ordinary cash-ledger row, so it
 * reaches the books through the same path as any other entry (GlSync, then JournalPoster, in the same database
 * transaction; a closed period refuses it and nothing is written), is audit-logged like any other entry, and is matched to
 * the statement line it explains in the same step. A BankAdjustment row says why it exists. Append-only.
 *
 * The approvals engine is respected, not bypassed: where a policy says an amount of this kind needs a second pair of eyes,
 * the adjustment is refused here and has to go through the flow that can collect the approval.
 */
class AdjustmentService
{
    use RecordsReconciliationHistory;

    public function __construct(private readonly MatchRecorder $recorder) {}

    public function bankCharge(BankReconciliation $session, User $user, int $cents, string $date, string $description, ?BankStatementLine $line = null, ?string $reason = null): Transaction
    {
        return $this->make($session, $user, 'bank_charge', 'out', $cents, $date, $description, 'Bank charges', $line, $reason ?? 'Bank charge on the statement');
    }

    public function interest(BankReconciliation $session, User $user, int $cents, string $date, string $description, ?BankStatementLine $line = null, ?string $reason = null): Transaction
    {
        return $this->make($session, $user, 'interest', 'in', $cents, $date, $description, 'Interest received', $line, $reason ?? 'Interest on the statement');
    }

    /**
     * Turn a statement line into the adjustment its words say it is: a bank charge, or interest (received or paid).
     */
    public function fromLine(BankReconciliation $session, User $user, BankStatementLine $line, string $kind): Transaction
    {
        $date = $line->posted_on->toDateString();
        $cents = abs($line->amount_cents);
        $description = (string) $line->description;

        if ($kind === 'bank_charge') {
            if ($line->amount_cents >= 0) {
                throw new ReconciliationRefused('invalid', 'A bank charge is money going out; this line is money coming in.');
            }

            return $this->bankCharge($session, $user, $cents, $date, $description, $line);
        }

        return $line->amount_cents > 0
            ? $this->interest($session, $user, $cents, $date, $description, $line)
            : $this->make($session, $user, 'interest', 'out', $cents, $date, $description, 'Interest paid', $line, 'Interest on the statement');
    }

    /**
     * The difference between a statement line and a ledger entry that is not quite the same amount (a short payment, a fee
     * taken off), recorded as its own ledger entry so the two sides can then be matched. It is not matched here.
     */
    public function difference(BankReconciliation $session, User $user, int $signedCents, string $date, string $description, string $reason): Transaction
    {
        return $this->make($session, $user, 'other', $signedCents > 0 ? 'in' : 'out', abs($signedCents), $date, $description, null, null, $reason, match: false);
    }

    /** A difference the person accepted: the ledger entry that makes the cleared balance equal the statement. */
    public function variance(BankReconciliation $session, Account $account, User $user, int $differenceCents, string $reason): Transaction
    {
        return $this->make($session, $user, 'variance', $differenceCents > 0 ? 'in' : 'out', abs($differenceCents), $session->statement_date->toDateString(), 'Reconciliation difference accepted', null, null, $reason);
    }

    private function make(BankReconciliation $session, User $user, string $kind, string $direction, int $cents, string $date, string $description, ?string $categoryName, ?BankStatementLine $line, string $reason, bool $match = true): Transaction
    {
        if ($cents <= 0) {
            throw new ReconciliationRefused('invalid', 'An adjustment must be greater than zero.');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            throw new ReconciliationRefused('bad_date', 'The date is not a valid date.');
        }
        if ($date > $session->statement_date->toDateString()) {
            throw new ReconciliationRefused('out_of_scope', 'An adjustment cannot be dated after the statement date.');
        }

        return DB::transaction(function () use ($session, $user, $kind, $direction, $cents, $date, $description, $categoryName, $line, $reason, $match) {
            $context = $session->ledgerContext();
            $locked = BankReconciliation::query()->forContext($context)->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft()) {
                throw new ReconciliationRefused('not_draft', 'This reconciliation is completed. Reopen it to add an adjustment.');
            }

            $account = Account::query()->forContext($context)->findOrFail($locked->account_id);

            $approvalKind = $kind === 'variance' ? 'journal' : ($direction === 'out' ? 'expense' : null);
            if ($approvalKind !== null && ApprovalService::requiresApproval($approvalKind, $cents, $account->currency, $context)) {
                throw new ReconciliationRefused('needs_approval', 'An approval policy covers this kind of entry at '.Money::format($cents, $account->currency).', so it cannot be created from the reconciliation. Record it through the flow that collects the approval, then match it here.');
            }

            if ($line !== null) {
                $line = BankStatementLine::query()->forContext($context)->where('account_id', $account->id)->whereKey($line->id)->lockForUpdate()->first();

                if ($line === null) {
                    throw new ReconciliationRefused('not_in_ledger', 'That statement line is not in this account.');
                }
                if ($line->bank_match_id !== null || $line->reconciliation_id !== null) {
                    throw new ReconciliationRefused('already_matched', 'That statement line is already matched.');
                }
                if (abs($line->amount_cents) !== $cents || ($line->amount_cents > 0) !== ($direction === 'in')) {
                    throw new ReconciliationRefused('totals_differ', 'The adjustment must be exactly the statement line ('.Money::format($line->amount_cents, $account->currency).').');
                }
            }

            $category = $categoryName !== null ? $this->category($context, $categoryName, $direction === 'in' ? 'income' : 'expense') : null;

            try {
                $transaction = Transaction::create($account->ledgerOwner() + [
                    'account_id' => $account->id,
                    'category_id' => $category?->id,
                    'description' => mb_substr($description, 0, 255),
                    'amount_cents' => $cents,
                    'currency' => $account->currency,
                    'type' => $direction === 'in' ? 'income' : 'expense',
                    'date' => $date,
                    'notes' => $kind === 'variance' ? 'Accepted during bank reconciliation' : 'Created during bank reconciliation',
                ]);

                // With accounting on, the entry reaches the books here, in this transaction, through JournalPoster.
                app(GlSync::class)->transaction($transaction);
            } catch (PeriodClosed $e) {
                throw new ReconciliationRefused('locked', $e->getMessage().' The adjustment cannot be dated in a closed period.');
            } catch (AccountingException $e) {
                throw new ReconciliationRefused('invalid', $e->getMessage());
            }

            $adjustment = BankAdjustment::create($account->ledgerOwner() + [
                'bank_reconciliation_id' => $locked->id,
                'account_id' => $account->id,
                'kind' => $kind,
                'direction' => $direction,
                'amount_cents' => $cents,
                'transaction_id' => $transaction->id,
                'bank_statement_line_id' => $line?->id,
                'reason' => mb_substr($reason, 0, 255),
                'created_by' => $user->id,
            ]);

            $signed = $direction === 'in' ? $cents : -$cents;
            if ($match) {
                $this->recorder->record(
                    $locked,
                    $line !== null ? [$line->id] : [],
                    [$transaction->id],
                    $signed,
                    $user,
                    $kind === 'variance' ? 'variance' : 'created',
                    null,
                    $kind === 'variance'
                        ? 'Difference accepted by '.$user->name.': '.mb_substr($reason, 0, 300)
                        : 'Created from the statement line: '.($kind === 'bank_charge' ? 'a bank charge' : 'interest').' you confirmed.',
                );
            }

            $this->event($locked, 'adjustment', $user, ['adjustment_id' => $adjustment->id, 'kind' => $kind, 'direction' => $direction, 'amount_cents' => $cents, 'transaction_id' => $transaction->id, 'line_id' => $line?->id]);
            $this->audit($locked, 'adjusted', ['kind' => ['after' => $kind], 'amount_cents' => ['after' => $cents], 'transaction_id' => ['after' => $transaction->id]], $user);

            return $transaction;
        });
    }

    /** The category called $name of the right type: the one that exists, or a new one. */
    private function category(LedgerContext $context, string $name, string $type): Category
    {
        $existing = Category::query()->forContext($context)->where('type', $type)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();

        return $existing ?? Category::create($context->ownerAttributes() + ['name' => $name, 'type' => $type, 'color' => '#4b5563']);
    }
}
