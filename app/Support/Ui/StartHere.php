<?php

namespace App\Support\Ui;

use App\Models\Account;
use App\Models\Purchases\Bill;
use App\Models\Sales\SalesDocument;
use App\Models\Transaction;
use Illuminate\Support\Facades\Gate;

/**
 * The short "Start here" list a brand-new ledger shows above the sidebar (R-44 polish, D-1710). The map of the product has more than
 * twenty destinations; someone who has not entered anything yet needs four steps, in order, not the whole map. The list leaves
 * by itself once the ledger has an account and an entry, and it is never shown to someone who cannot record anything.
 *
 * Each step is ['key', 'label', 'url', 'done'] and only steps whose route exists are listed.
 */
final class StartHere
{
    /** @return list<array{key: string, label: string, url: string, done: bool}> */
    public static function for(): array
    {
        if (! Gate::allows('record-entries')) {
            return [];
        }

        $hasAccount = Account::query()->exists();
        $hasEntry = Transaction::query()->exists();

        if ($hasAccount && $hasEntry) {
            return [];
        }

        $steps = [
            ['key' => 'account', 'label' => 'Add an account', 'routes' => ['accounts.index'], 'done' => $hasAccount],
            ['key' => 'entry', 'label' => 'Add a transaction', 'routes' => ['transactions.create'], 'done' => $hasEntry],
            ['key' => 'invoice', 'label' => 'Create an invoice', 'routes' => ['sales.invoices.create', 'invoices.create'], 'done' => class_exists(SalesDocument::class) && SalesDocument::query()->exists()],
            ['key' => 'bill', 'label' => 'Capture a bill', 'routes' => ['purchases.bills.create', 'bills.create'], 'done' => class_exists(Bill::class) && Bill::query()->exists()],
        ];

        $out = [];
        foreach ($steps as $step) {
            $url = fx_route($step['routes']);
            if ($url !== null) {
                $out[] = ['key' => $step['key'], 'label' => $step['label'], 'url' => $url, 'done' => $step['done']];
            }
        }

        return $out;
    }
}
