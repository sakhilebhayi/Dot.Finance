<?php

namespace App\Support\Ui;

/**
 * Extra panels for the command centre. A module that has something an owner must see (overdue invoices, bills due,
 * the VAT payment date) registers a Blade view from a service provider's boot(); the dashboard includes it, in
 * order, after the built-in panels. The view is only rendered when the page is, and it must draw nothing at all
 * (return an empty string) when it has no data: the command centre never shows a placeholder figure.
 *
 *   DashboardPanels::add('sales.dashboard.overdue', order: 20);
 *
 * Each view receives $activeLedger (a LedgerContext) and should wrap itself in <x-fx.panel>.
 */
final class DashboardPanels
{
    /** @var list<array{view: string, order: int}> */
    private static array $panels = [];

    public static function add(string $view, int $order = 100): void
    {
        self::$panels[] = ['view' => $view, 'order' => $order];
    }

    /** @return list<string> */
    public static function views(): array
    {
        $panels = self::$panels;
        usort($panels, fn ($a, $b) => $a['order'] <=> $b['order']);

        return array_values(array_unique(array_map(fn ($p) => $p['view'], $panels)));
    }

    public static function flush(): void
    {
        self::$panels = [];
    }
}
