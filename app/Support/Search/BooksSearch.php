<?php

namespace App\Support\Search;

use App\Models\Account;
use App\Models\Purchases\Bill;
use App\Models\Sales\SalesDocument;
use App\Models\Shared\Party;
use App\Models\Tax\TaxReturn;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Ledger\LedgerAccess;
use App\Support\Ui\Navigation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * The header search of Dot.Finance: what a person typed, looked for in the books that are OPEN.
 *
 * Nothing here chooses a ledger. Every model below carries the shared ledger scope (HasLedgerContext / LedgerContextScope), so a query
 * is confined to the active personal or company ledger, and a row of another ledger can never come back. On top of that each group
 * is asked for only when the person's role may open that list (the same policies and gates the list pages use): a viewer sees what a
 * viewer can open, and no one sees a group they could not follow to a page.
 *
 * The transactions group is the search Finance already had (a description that contains the words, newest first). The other groups add
 * only entities that exist, each linking to a page that exists: accounts (to the transactions of that account), customers and
 * suppliers, invoices and quotes, bills, VAT/EMP201 returns, and the places in the sidebar. Every group is limited, the words are
 * bound parameters with LIKE wildcards escaped, and nothing is invented: no result is a score, a guess or a figure.
 *
 * @phpstan-type Row array{href: string, label: string, code?: string, tag?: string}
 */
final class BooksSearch
{
    public const MIN = 2;

    public const MAX = 100;

    /** Rows per group; the dropdown is short on purpose, the full lists are one click away. */
    public const LIMIT = 5;

    /**
     * @return list<array{head: string, rows: list<Row>}>
     */
    public function run(User $user, string $query): array
    {
        $query = trim(preg_replace('/\s+/u', ' ', $query) ?? '');

        if (mb_strlen($query) < self::MIN) {
            return [];
        }

        $query = mb_substr($query, 0, self::MAX);
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query).'%';

        // A person with no access to the open ledger gets nothing at all.
        if (LedgerAccess::current($user) === null) {
            return [];
        }

        $groups = [
            $this->transactions($user, $like),
            $this->accounts($user, $like),
            $this->customers($user, $like),
            $this->suppliers($user, $like),
            $this->documents($user, $like),
            $this->bills($user, $like),
            $this->taxReturns($user, $like),
            $this->pages($query),
        ];

        $groups = array_values(array_filter($groups, fn ($group) => $group !== null && $group['rows'] !== []));

        if (Gate::forUser($user)->allows('viewAny', Transaction::class) && fx_route_exists('transactions.index')) {
            $groups[] = ['head' => '', 'rows' => [['href' => route('transactions.index', ['search' => $query]), 'label' => 'Search all transactions for "'.$query.'"', 'action' => true]]];
        }

        return $groups;
    }

    /** Whether $column contains the words, case-insensitively, with the escape character the other lists use. */
    private function contains(Builder $builder, array $columns, string $like): Builder
    {
        return $builder->where(function (Builder $where) use ($columns, $like) {
            foreach ($columns as $column) {
                $where->orWhereRaw('LOWER('.$column.") LIKE LOWER(?) ESCAPE '\\'", [$like]);
            }
        });
    }

    private function transactions(User $user, string $like): ?array
    {
        if (! Gate::forUser($user)->allows('viewAny', Transaction::class) || ! fx_route_exists('transactions.index')) {
            return null;
        }

        $rows = $this->contains(Transaction::query(), ['description'], $like)->latest('date')->latest('id')->limit(self::LIMIT)->get(['id', 'description', 'date']);

        return ['head' => 'Transactions', 'rows' => $rows->map(fn (Transaction $t) => [
            'href' => route('transactions.index', ['search' => $t->description]),
            'label' => (string) $t->description,
            'tag' => $t->date?->toDateString(),
        ])->all()];
    }

    private function accounts(User $user, string $like): ?array
    {
        if (! Gate::forUser($user)->allows('viewAny', Account::class) || ! fx_route_exists('transactions.index')) {
            return null;
        }

        $rows = $this->contains(Account::query(), ['name', 'institution'], $like)->orderBy('name')->limit(self::LIMIT)->get(['id', 'name', 'type', 'currency']);

        return ['head' => 'Accounts', 'rows' => $rows->map(fn (Account $a) => [
            'href' => route('transactions.index', ['account_id' => $a->id]),
            'label' => (string) $a->name,
            'tag' => (string) $a->currency,
        ])->all()];
    }

    private function customers(User $user, string $like): ?array
    {
        if (! Gate::forUser($user)->allows('view-finance') || ! fx_route_exists('sales.customers.show')) {
            return null;
        }

        $rows = $this->contains(Party::query()->customers()->active(), ['name', 'legal_name', 'email'], $like)->orderBy('name')->limit(self::LIMIT)->get(['id', 'name', 'email']);

        return ['head' => 'Customers', 'rows' => $rows->map(fn (Party $p) => [
            'href' => route('sales.customers.show', $p),
            'label' => (string) $p->name,
        ])->all()];
    }

    private function suppliers(User $user, string $like): ?array
    {
        if (! Gate::forUser($user)->allows('viewAny', Party::class) || ! fx_route_exists('purchases.suppliers.show')) {
            return null;
        }

        $rows = $this->contains(Party::query()->suppliers()->active(), ['name', 'legal_name', 'email'], $like)->orderBy('name')->limit(self::LIMIT)->get();

        return ['head' => 'Suppliers', 'rows' => $rows->filter(fn (Party $p) => Gate::forUser($user)->allows('view', $p))->map(fn (Party $p) => [
            'href' => route('purchases.suppliers.show', $p),
            'label' => (string) $p->name,
        ])->values()->all()];
    }

    private function documents(User $user, string $like): ?array
    {
        if (! Gate::forUser($user)->allows('viewAny', SalesDocument::class) || ! fx_route_exists('sales.documents.show')) {
            return null;
        }

        // One group around the OR, so the ledger scope the model adds applies to every branch (a stray OR would escape it).
        $query = SalesDocument::query()->with('party:id,name')
            ->where(fn (Builder $w) => $this->contains($w, ['number', 'reference'], $like)->orWhereHas('party', fn (Builder $p) => $this->contains($p, ['name'], $like)));

        $rows = $query->orderByDesc('issue_date')->orderByDesc('id')->limit(self::LIMIT)->get();
        $kinds = ['quote' => 'Quote', 'pro_forma' => 'Pro forma', 'tax_invoice' => 'Invoice', 'credit_note' => 'Credit note', 'debit_note' => 'Debit note'];

        return ['head' => 'Invoices and quotes', 'rows' => $rows->filter(fn (SalesDocument $d) => Gate::forUser($user)->allows('view', $d))->map(fn (SalesDocument $d) => [
            'href' => route('sales.documents.show', $d),
            'code' => $d->number ? (string) $d->number : 'Draft',
            'label' => (string) ($d->party?->name ?? $d->reference ?? ''),
            'tag' => $kinds[$d->kind] ?? null,
        ])->values()->all()];
    }

    private function bills(User $user, string $like): ?array
    {
        if (! Gate::forUser($user)->allows('viewAny', Bill::class) || ! fx_route_exists('purchases.bills.show')) {
            return null;
        }

        $query = Bill::query()->with('party:id,name')
            ->where(fn (Builder $w) => $this->contains($w, ['number', 'supplier_reference'], $like)->orWhereHas('party', fn (Builder $p) => $this->contains($p, ['name'], $like)));

        $rows = $query->orderByDesc('bill_date')->orderByDesc('id')->limit(self::LIMIT)->get();

        return ['head' => 'Bills', 'rows' => $rows->filter(fn (Bill $b) => Gate::forUser($user)->allows('view', $b))->map(fn (Bill $b) => [
            'href' => route('purchases.bills.show', $b),
            'code' => $b->number ? (string) $b->number : 'Draft',
            'label' => (string) ($b->party?->name ?? $b->supplier_reference ?? ''),
            'tag' => $b->kind === 'credit_note' ? 'Supplier credit' : 'Bill',
        ])->values()->all()];
    }

    private function taxReturns(User $user, string $like): ?array
    {
        if (! fx_route_exists('tax.returns.show')) {
            return null;
        }

        $rows = $this->contains(TaxReturn::query(), ['period_label', 'kind', 'sars_reference'], $like)->orderByDesc('period_start')->orderByDesc('id')->limit(self::LIMIT)->get();

        return ['head' => 'Tax periods', 'rows' => $rows->map(fn (TaxReturn $r) => [
            'href' => route('tax.returns.show', $r),
            'code' => strtoupper((string) $r->kind),
            'label' => (string) $r->period_label,
            'tag' => $r->status ? ucfirst(str_replace('_', ' ', (string) $r->status)) : null,
        ])->all()];
    }

    /** The places in the sidebar (and only those this person may open and that exist): the same list the quick-actions bar filters. */
    private function pages(string $query): ?array
    {
        $needle = mb_strtolower($query);
        $rows = [];

        foreach (Navigation::resolved() as $group) {
            foreach ($group['items'] as $item) {
                if ($item['available'] && str_contains(mb_strtolower($group['label'].' '.$item['label']), $needle)) {
                    $rows[] = ['href' => (string) $item['url'], 'label' => (string) $item['label'], 'tag' => (string) $group['label']];
                }
            }
        }

        return ['head' => 'Pages', 'rows' => array_slice($rows, 0, self::LIMIT)];
    }
}
