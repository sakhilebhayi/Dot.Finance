<?php

return [
    /*
     | Exports larger than this many rows (or any export asked to run in the background) are queued and delivered from
     | the status page; smaller ones stream straight to the browser.
     */
    'export_queue_threshold_rows' => (int) env('REPORTING_EXPORT_QUEUE_ROWS', 5000),

    'export_disk' => env('REPORTING_EXPORT_DISK', 'local'),

    'export_retention_days' => (int) env('REPORTING_EXPORT_RETENTION_DAYS', 14),

    // Signed download links stay valid this long (minutes).
    'download_link_minutes' => 30,

    // Aggregates are cached against the ledger's journal sequence, so they are never stale; this is only the memory cap.
    'cache_ttl_seconds' => 86400,

    /*
     | Cash basis: which journal entries are documents whose profit-and-loss lines are recognised as they are paid, and
     | how a payment allocation names them. `allocatable` lists the (snake-cased) type names Sales and Purchases store in
     | payment_allocations.allocatable_type; `control` is the system account that carries the document's gross amount.
     | The keys are the journal source types Sales and Purchases really post ('invoice' for invoices and debit notes, 'bill' for
     | bills and expense claims; credit notes post as 'credit_note' and 'bill' with no receivable/payable debit, and are taken as
     | posted); `allocatable` holds the snake-cased class names the allocations carry ('sales_document' is
     | App\Models\Sales\SalesDocument, 'bill' and 'expense_claim' are Purchases' morph aliases). tests/Feature/Integration/
     | CashBasisWiringTest fails if either side drifts.
     */
    'cash_basis' => [
        'documents' => [
            'invoice' => ['control' => 'ar', 'side' => 'debit', 'allocatable' => ['invoice', 'sales_document', 'sales_invoice', 'stub_invoice']],
            'bill' => ['control' => 'ap', 'side' => 'credit', 'allocatable' => ['bill', 'expense_claim', 'purchase_bill', 'supplier_bill', 'stub_bill']],
        ],
    ],

    // Ageing buckets, in days past the due date. The first bucket is "not yet due".
    'ageing_buckets' => [30, 60, 90],

    // The longest forecast history used and the forecast horizon.
    'forecast' => [
        'min_history_months' => 6,
        'seasonal_min_months' => 24,
        'backtest_periods' => 3,
        'horizon_months' => 6,
    ],

    'projection' => [
        'lateness_min_paid_invoices' => 3,
    ],
];
