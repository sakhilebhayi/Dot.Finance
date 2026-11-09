<?php

namespace App\Services\Intelligence\Detectors;

use App\Models\Accounting\LedgerSetting;
use App\Services\Accounting\GlQuery;
use App\Services\Accounting\GlSweeper;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * The cash ledger (the Transactions list and account balances) and the books (the posted journals) must say the same
 * thing about every cash account. When they do not, the tax estimate and the reports (which read the books) will not agree
 * with the Transactions page, and a person needs to be told rather than left to find out. Reuses the two existing
 * checks (the cash tie-out and the rows the journals have not picked up yet); decides nothing new.
 */
final class BooksVsCashDetector implements Detector
{
    public function __construct(private readonly GlQuery $query, private readonly GlSweeper $sweeper) {}

    public function kind(): string
    {
        return 'books_vs_cash';
    }

    public function period(CarbonImmutable $asOf): string
    {
        return 'ongoing';
    }

    public function detect(LedgerContext $context, CarbonImmutable $asOf): array
    {
        $settings = LedgerSetting::query()->forContext($context)->first();
        if (! $settings?->isEnabled()) {
            return [];
        }

        $findings = [];

        $bad = array_values(array_filter($this->query->cashTieOut($context), fn (array $r) => ! $r['ok']));
        if ($bad !== []) {
            $n = count($bad);
            $findings[] = new Finding(
                $this->kind(),
                'tie-out',
                $this->period($asOf),
                'warning',
                $n === 1 ? '1 cash account does not agree with the books' : "{$n} cash accounts do not agree with the books",
                'The balance in the Transactions list differs from what the posted journals hold for '.($n === 1 ? 'this account' : 'these accounts').'. Reports and the tax estimate read the books, so they will not match the Transactions page until this is resolved.',
                array_map(fn (array $r) => ['label' => $r['name'].' ('.$r['currency'].'), difference', 'value' => Money::format($r['difference_cents'], $r['currency']).': Transactions '.Money::format($r['cash_cents'], $r['currency']).', books '.Money::format($r['gl_cents'], $r['currency'])], array_slice($bad, 0, 6)),
                fx_route('accounts.index'),
            );
        }

        $p = $this->sweeper->pending($context);
        $pending = count($p['transactions']) + count($p['transfers']) + count($p['openings']);
        if ($pending > 0) {
            $findings[] = new Finding(
                $this->kind(),
                'not-posted',
                $this->period($asOf),
                'notice',
                $pending === 1 ? '1 transaction is not in the books yet' : "{$pending} transactions are not in the books yet",
                'These rows are in the Transactions list but the posted journals have not picked them up, so the reports and the tax estimate leave them out. They are posted automatically; if this stays, ask whoever looks after the books to post them.',
                [['label' => 'Waiting', 'value' => count($p['transactions']).' transaction(s), '.count($p['transfers']).' transfer(s), '.count($p['openings']).' opening balance(s)']],
                fx_route('transactions.index'),
            );
        }

        return $findings;
    }
}
