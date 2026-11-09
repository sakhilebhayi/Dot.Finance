# Dot.Analytics: the Dot.Finance snapshot

Dot.Finance publishes one daily snapshot per **company** ledger (never a personal ledger) to `POST /api/ingest/snapshots` in the shape Dot.Pulse and Dot.Auction use. Dot.Analytics must add the entry below to its metric catalogue (`config/metrics.php`) and `ANALYTICS_INGEST_SECRET_FINANCE` to its environment before it will accept the call; until then it answers a generic 401 and this app logs one error and gives up for the day.

```php
'finance' => [
    'income_mtd_cents'   => ['label' => 'Income this month', 'type' => 'cents', 'good' => 'up'],
    'expense_mtd_cents'  => ['label' => 'Expenses this month', 'type' => 'cents', 'good' => 'neutral'],
    'transactions_mtd'   => ['label' => 'Transactions this month', 'type' => 'count', 'good' => 'neutral'],
    'accounts'           => ['label' => 'Accounts', 'type' => 'count', 'good' => 'neutral'],
    'open_findings'      => ['label' => 'Open findings', 'type' => 'count', 'good' => 'down'],
    'pending_approvals'  => ['label' => 'Approvals waiting', 'type' => 'count', 'good' => 'down'],
    'receivables_cents'  => ['label' => 'Owed by customers', 'type' => 'cents', 'good' => 'neutral'],
],
```

Aggregates only: no description, party, account or category name, no individual amount, no person. Every value is a non-negative whole number (a figure that could be negative, such as a balance, is not published); money is in one currency named by the `currency` key. `taken_at` is the start of the UTC day, so a retry or second run is the same snapshot. `php artisan finance:publish-metrics --dry-run` prints exactly what would be sent.
