<?php

namespace App\Support\Ui;

use Illuminate\Support\Facades\Gate;

/**
 * The map of the product: eight groups, each a list of destinations. It is the single source for the sidebar, the
 * mobile bottom sheet and the command bar.
 *
 * Destinations are described by route NAME (with alternatives), not by URL. A destination whose route is not
 * registered yet resolves to no URL and is drawn as a disabled item marked "Soon"; it is never a link, so the
 * shell cannot send anyone to a 404 while a module is still being built. The moment a module registers one of the
 * names listed here the item becomes a normal link with no change to the shell.
 *
 * A module that wants an item the map does not list adds it from a service provider:
 *   Navigation::add('sales', ['key' => 'credit-notes', 'label' => 'Credit notes', 'icon' => 'file-text', 'routes' => ['sales.credit-notes.index']]);
 */
final class Navigation
{
    /** @var array<string, list<array<string, mixed>>>|null */
    private static ?array $added = null;

    /** @return list<array{key: string, label: string}> */
    public static function groupLabels(): array
    {
        return [
            ['key' => 'money', 'label' => 'Money'],
            ['key' => 'sales', 'label' => 'Sales'],
            ['key' => 'purchases', 'label' => 'Purchases'],
            ['key' => 'bank', 'label' => 'Bank'],
            ['key' => 'tax', 'label' => 'Tax'],
            ['key' => 'reports', 'label' => 'Reports'],
            ['key' => 'planning', 'label' => 'Planning'],
            ['key' => 'controls', 'label' => 'Controls'],
        ];
    }

    /** Add (or replace by key) a destination in a group. Call from a service provider's boot(). */
    public static function add(string $group, array $item): void
    {
        self::$added ??= [];
        self::$added[$group] = array_values(array_filter(self::$added[$group] ?? [], fn ($i) => $i['key'] !== $item['key']));
        self::$added[$group][] = $item;
    }

    public static function flush(): void
    {
        self::$added = null;
    }

    /**
     * @return list<array<string, mixed>> the groups with every item resolved for the current person:
     *                                    ['key','label','items' => [['key','label','icon','url'|null,'active','available','match']], 'open']
     */
    public static function resolved(?string $currentRoute = null): array
    {
        $groups = [];

        foreach (self::groupLabels() as $group) {
            $items = [];

            foreach (array_merge(self::definitions()[$group['key']] ?? [], self::$added[$group['key']] ?? []) as $item) {
                if (isset($item['can']) && ! Gate::allows($item['can'])) {
                    continue;
                }

                $url = fx_route($item['routes']);
                $patterns = $item['match'] ?? array_map(fn ($name) => preg_replace('/\.[^.]+$/', '.*', $name), $item['routes']);

                $items[] = [
                    'key' => $item['key'],
                    'label' => $item['label'],
                    'icon' => $item['icon'],
                    'url' => $url,
                    'available' => $url !== null,
                    'active' => $url !== null && $currentRoute !== null && self::matches($currentRoute, $patterns),
                    'action' => $item['action'] ?? null,
                ];
            }

            if ($items === []) {
                continue;
            }

            $group['items'] = $items;
            $group['open'] = (bool) array_filter($items, fn ($i) => $i['active']) || (bool) array_filter($items, fn ($i) => $i['available']);
            $groups[] = $group;
        }

        return $groups;
    }

    /**
     * Quick actions for the command bar: things you do, not places you go. Only those whose route exists.
     *
     * @return list<array{key: string, label: string, icon: string, url: string}>
     */
    public static function quickActions(): array
    {
        $defs = [
            ['key' => 'add-transaction', 'label' => 'Add a transaction', 'icon' => 'plus', 'routes' => ['transactions.create'], 'can' => 'record-entries'],
            ['key' => 'record-transfer', 'label' => 'Record a transfer', 'icon' => 'arrow-left-right', 'routes' => ['transfers.create'], 'can' => 'record-entries'],
            ['key' => 'import-csv', 'label' => 'Import a bank statement (CSV)', 'icon' => 'upload', 'routes' => ['transactions.import.create'], 'can' => 'record-entries'],
            ['key' => 'new-invoice', 'label' => 'Create an invoice', 'icon' => 'file-text', 'routes' => ['sales.invoices.create', 'invoices.create'], 'can' => 'record-entries'],
            ['key' => 'new-bill', 'label' => 'Capture a bill', 'icon' => 'receipt', 'routes' => ['purchases.bills.create', 'bills.create'], 'can' => 'record-entries'],
            ['key' => 'view-tieout', 'label' => 'Check that balances tie out', 'icon' => 'shield-check', 'routes' => ['reconciliation.index']],
        ];

        $actions = [];
        foreach ($defs as $def) {
            if (isset($def['can']) && ! Gate::allows($def['can'])) {
                continue;
            }
            $url = fx_route($def['routes']);
            if ($url !== null) {
                $actions[] = ['key' => $def['key'], 'label' => $def['label'], 'icon' => $def['icon'], 'url' => $url];
            }
        }

        return $actions;
    }

    /** @param  list<string>  $patterns */
    private static function matches(string $route, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($route === $pattern || (str_contains($pattern, '*') && preg_match('/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/', $route))) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, list<array<string, mixed>>> */
    private static function definitions(): array
    {
        return [
            'money' => [
                ['key' => 'dashboard', 'label' => 'Command centre', 'icon' => 'layout-dashboard', 'routes' => ['dashboard']],
                ['key' => 'transactions', 'label' => 'Transactions', 'icon' => 'receipt-text', 'routes' => ['transactions.index'], 'match' => ['transactions.*']],
                ['key' => 'transfers', 'label' => 'Transfers', 'icon' => 'arrow-left-right', 'routes' => ['transfers.create'], 'can' => 'record-entries', 'match' => ['transfers.*']],
                ['key' => 'accounts', 'label' => 'Accounts', 'icon' => 'landmark', 'routes' => ['accounts.index'], 'match' => ['accounts.*']],
                ['key' => 'categories', 'label' => 'Categories', 'icon' => 'tags', 'routes' => ['categories.index'], 'match' => ['categories.*']],
                ['key' => 'recurring', 'label' => 'Recurring', 'icon' => 'repeat', 'routes' => ['recurring.index'], 'match' => ['recurring.*']],
            ],
            'sales' => [
                ['key' => 'invoices', 'label' => 'Invoices', 'icon' => 'file-text', 'routes' => ['sales.invoices.index', 'invoices.index']],
                ['key' => 'quotes', 'label' => 'Quotes', 'icon' => 'file-clock', 'routes' => ['sales.quotes.index', 'quotes.index']],
                ['key' => 'customers', 'label' => 'Customers', 'icon' => 'users', 'routes' => ['parties.customers.index', 'customers.index', 'sales.customers.index', 'parties.index']],
            ],
            'purchases' => [
                ['key' => 'bills', 'label' => 'Bills', 'icon' => 'receipt', 'routes' => ['purchases.bills.index', 'bills.index']],
                ['key' => 'suppliers', 'label' => 'Suppliers', 'icon' => 'truck', 'routes' => ['parties.suppliers.index', 'suppliers.index', 'purchases.suppliers.index']],
                ['key' => 'claims', 'label' => 'Expense claims', 'icon' => 'hand-coins', 'routes' => ['purchases.claims.index', 'claims.index']],
            ],
            'bank' => [
                ['key' => 'bank-lines', 'label' => 'Statements', 'icon' => 'scan-search', 'routes' => ['bank.index', 'bank.statements.index']],
                ['key' => 'bank-rec', 'label' => 'Bank reconciliation', 'icon' => 'git-compare', 'routes' => ['bank.reconciliation.index']],
            ],
            'tax' => [
                ['key' => 'tax-overview', 'label' => 'Tax overview', 'icon' => 'badge-percent', 'routes' => ['tax.index']],
                ['key' => 'vat', 'label' => 'VAT', 'icon' => 'percent', 'routes' => ['tax.vat.index', 'vat.index']],
                ['key' => 'tax-calendar', 'label' => 'Tax calendar', 'icon' => 'calendar-days', 'routes' => ['tax.calendar.index', 'tax.calendar']],
            ],
            'reports' => [
                ['key' => 'reports-hub', 'label' => 'All reports', 'icon' => 'book-open', 'routes' => ['reports.index']],
                ['key' => 'cash-flow', 'label' => 'Cash flow', 'icon' => 'chart-column', 'routes' => ['reports.cash-flow']],
                ['key' => 'profit-loss', 'label' => 'Profit and loss', 'icon' => 'chart-line', 'routes' => ['reports.profit-loss', 'reports.pnl']],
                ['key' => 'balance-sheet', 'label' => 'Balance sheet', 'icon' => 'scale', 'routes' => ['reports.balance-sheet']],
                ['key' => 'trial-balance', 'label' => 'Trial balance', 'icon' => 'scale', 'routes' => ['accounting.trial-balance']],
                ['key' => 'general-ledger', 'label' => 'General ledger', 'icon' => 'book-open', 'routes' => ['accounting.general-ledger']],
                ['key' => 'insights', 'label' => 'Weekly summaries', 'icon' => 'file-text', 'routes' => ['insights.index'], 'match' => ['insights.*']],
            ],
            'planning' => [
                ['key' => 'forecast', 'label' => 'Forecast', 'icon' => 'chart-line', 'routes' => ['forecast.index'], 'match' => ['forecast.*']],
                ['key' => 'budgets', 'label' => 'Budgets', 'icon' => 'piggy-bank', 'routes' => ['budgets.index'], 'match' => ['budgets.*']],
                ['key' => 'scenarios', 'label' => 'Scenarios', 'icon' => 'sliders-horizontal', 'routes' => ['planning.scenarios.index']],
            ],
            'controls' => [
                ['key' => 'tieout', 'label' => 'Balance tie-out', 'icon' => 'shield-check', 'routes' => ['reconciliation.index'], 'match' => ['reconciliation.*']],
                ['key' => 'audit', 'label' => 'Audit log', 'icon' => 'history', 'routes' => ['audit.index'], 'can' => 'view-audit', 'match' => ['audit.*']],
                ['key' => 'approvals', 'label' => 'Approvals', 'icon' => 'list-checks', 'routes' => ['controls.approvals.index', 'approvals.index']],
                ['key' => 'access', 'label' => 'Roles and access', 'icon' => 'key-round', 'routes' => ['controls.members.index', 'controls.access.index', 'controls.roles.index']],
                ['key' => 'periods', 'label' => 'Period locks', 'icon' => 'lock', 'routes' => ['controls.periods.index', 'accounting.periods.index']],
                ['key' => 'chart', 'label' => 'Chart of accounts', 'icon' => 'book-check', 'routes' => ['accounting.chart.index', 'accounting.accounts.index', 'gl.accounts.index', 'chart.index']],
                ['key' => 'journals', 'label' => 'Journals', 'icon' => 'layers', 'routes' => ['accounting.journals.index', 'journals.index']],
                ['key' => 'policies', 'label' => 'Approval rules', 'icon' => 'list-checks', 'routes' => ['controls.policies.index']],
                ['key' => 'limits', 'label' => 'Spending limits', 'icon' => 'gauge', 'routes' => ['controls.limits.index']],
                ['key' => 'access-review', 'label' => 'Access review', 'icon' => 'user-check', 'routes' => ['controls.access-review.index']],
                ['key' => 'accounting-setup', 'label' => 'Accounting set-up', 'icon' => 'settings-2', 'routes' => ['accounting.setup']],
            ],
        ];
    }
}
