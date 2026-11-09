<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use App\Models\Shared\Attachment;
use App\Models\Shared\Party;
use App\Services\Shared\Attachments;
use App\Support\Shared\Exceptions\AttachmentRejected;
use App\Support\Shared\Exceptions\DuplicateAttachment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Upload a document (a contract, a VAT certificate) to a customer or supplier. */
class PartyAttachmentController extends Controller
{
    public function store(Request $request, Party $party, Attachments $attachments): RedirectResponse
    {
        $this->authorize('attach', $party);
        $this->authorize('create', Attachment::class);

        $request->validate([
            'file' => ['required', 'file'],
            'allow_duplicate' => ['nullable', 'boolean'],
        ]);

        try {
            $stored = $attachments->store($request->file('file'), $party, $request->user(), allowDuplicate: $request->boolean('allow_duplicate'), context: $this->ledger());
        } catch (DuplicateAttachment $e) {
            return back()->withErrors(['file' => $e->getMessage().' Tick "Keep it anyway" to store a second copy.']);
        } catch (AttachmentRejected $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return redirect()->route('parties.show', $party)->with('status', $stored->wasRecentlyCreated ? 'Document added.' : 'That document was already on file for this record, so nothing new was added.');
    }
}
