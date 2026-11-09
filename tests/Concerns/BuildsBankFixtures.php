<?php

namespace Tests\Concerns;

use App\Models\Account;
use App\Models\Bank\BankReconciliation;
use App\Models\Bank\BankStatement;
use App\Models\Bank\BankStatementLine;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\GlSync;
use App\Services\Bank\ReconciliationService;
use App\Services\Bank\StatementFingerprint;
use App\Support\Ledger\LedgerContext;
use Illuminate\Support\Str;

/**
 * Statement lines and ledger rows for the bank tests, written the way the real code writes them (statements through the
 * model, ledger rows through Transaction::create and GlSync, sessions through ReconciliationService).
 */
trait BuildsBankFixtures
{
    use BuildsAccounting;
    use BuildsCompanyLedgers;

    private int $lineSeq = 0;

    /** @var array<int, BankStatement> */
    private array $statements = [];

    protected function statementFor(Account $account, string $from = '2026-10-01', string $to = '2026-10-31'): BankStatement
    {
        return $this->statements[$account->id] ??= BankStatement::create($account->ledgerOwner() + [
            'account_id' => $account->id, 'source' => 'csv', 'original_name' => 'fixture.csv', 'file_sha256' => hash('sha256', Str::uuid()->toString()),
            'currency' => $account->currency, 'date_from' => $from, 'date_to' => $to, 'line_count' => 0, 'duplicate_count' => 0,
        ]);
    }

    protected function bankLine(Account $account, string $date, int $cents, string $description = 'Statement line', ?string $reference = null): BankStatementLine
    {
        $statement = $this->statementFor($account);
        $this->lineSeq++;

        return BankStatementLine::create($account->ledgerOwner() + [
            'bank_statement_id' => $statement->id, 'account_id' => $account->id, 'line_no' => $this->lineSeq, 'posted_on' => $date,
            'amount_cents' => $cents, 'currency' => $account->currency, 'description' => $description, 'reference' => $reference,
            'fingerprint' => StatementFingerprint::make($account->id, $date, $cents, $description.$this->lineSeq, 1),
        ]);
    }

    /** A cash-ledger row; $cents is signed (negative = expense). Journalled when accounting is on. */
    protected function ledgerRow(Account $account, string $date, int $cents, string $description = 'Ledger entry', ?Category $category = null): Transaction
    {
        return LedgerContext::run($account->ledgerContext(), function () use ($account, $date, $cents, $description, $category) {
            $row = Transaction::create($account->ledgerOwner() + [
                'account_id' => $account->id, 'category_id' => $category?->id, 'description' => $description, 'amount_cents' => abs($cents),
                'currency' => $account->currency, 'type' => $cents < 0 ? 'expense' : 'income', 'date' => $date,
            ]);
            app(GlSync::class)->transaction($row);

            return $row;
        });
    }

    protected function openSession(User $user, Account $account, string $date, int $closing, ?int $opening = null): BankReconciliation
    {
        return LedgerContext::run($account->ledgerContext(), fn () => app(ReconciliationService::class)->start($user, $account, $date, $closing, $opening));
    }

    /** Run $callback inside the ledger of $account, as the services do. */
    protected function inLedger(Account $account, callable $callback): mixed
    {
        return LedgerContext::run($account->ledgerContext(), $callback);
    }
}
