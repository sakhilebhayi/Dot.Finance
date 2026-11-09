<?php

namespace App\Http\Controllers\Purchases;

use App\Enums\Capability;
use App\Models\Purchases\PurchaseSetting;
use App\Models\Transaction;
use App\Services\Purchases\MissingDocuments;
use App\Services\Shared\Attachments;
use App\Support\Shared\Exceptions\AttachmentRejected;
use App\Support\Shared\Exceptions\DuplicateAttachment;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** The "needs documents" queue: spending with no document behind it, and a place to attach one to a cash-ledger expense. */
class DocumentController extends PurchasesController
{
    public function index(MissingDocuments $missing): View
    {
        Gate::authorize(Capability::ViewFinance->value);

        return view('purchases.documents.index', [
            'items' => $missing->forLedger($this->ledger()), 'threshold' => PurchaseSetting::forLedger($this->ledger())->missingDocumentThresholdCents(), 'canAttach' => $this->gate(Capability::RecordEntries),
        ]);
    }

    public function attachToTransaction(Request $request, Transaction $transaction, Attachments $attachments): RedirectResponse
    {
        $this->authorize('update', $transaction);
        abort_unless($transaction->type === 'expense', 404);
        $request->validate(['file' => ['required', 'file', 'max:15360']]);

        try {
            $attachments->store($request->file('file'), $transaction, $this->user($request), false, $this->ledger());
        } catch (DuplicateAttachment $e) {
            return $this->duplicateFile($e);
        } catch (AttachmentRejected $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return back()->with('status', 'Document attached.');
    }
}
