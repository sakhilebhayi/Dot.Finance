<?php

namespace App\Http\Controllers\Tax;

use App\Enums\Capability;
use App\Models\Tax\TaxCorrespondence;
use App\Models\Tax\TaxDeadline;
use App\Models\Tax\TaxDocument;
use App\Services\Shared\Attachments;
use App\Services\Tax\Exceptions\TaxException;
use App\Services\Tax\MissingRecords;
use App\Services\Tax\TaxDocuments;
use App\Support\Shared\Exceptions\AttachmentRejected;
use App\Support\Shared\Exceptions\DuplicateAttachment;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DocumentController extends TaxController
{
    public function index(Request $request, MissingRecords $missing): View|RedirectResponse
    {
        $this->access();
        $profile = $this->profileOrWizard();
        if ($profile instanceof RedirectResponse) {
            return $profile;
        }

        $category = $request->query('category');
        $docs = TaxDocument::query()->forContext($this->ctx())->whereNull('archived_at')
            ->when(is_string($category) && array_key_exists($category, TaxDocument::CATEGORIES), fn ($q) => $q->where('category', $category))
            ->orderByDesc('document_date')->orderByDesc('id')->limit(200)->get();

        $needs = [];
        foreach (TaxDeadline::query()->forContext($this->ctx())->whereIn('obligation', ['vat201', 'emp201'])->where('period_end', '<', CarbonImmutable::today()->toDateString())->orderByDesc('period_end')->limit(4)->get() as $d) {
            foreach ($missing->forDeadline($this->ctx(), $d, $profile) as $item) {
                $needs[] = $item + ['deadline' => $d];
            }
        }

        return view('tax.documents.index', [
            'profile' => $profile, 'docs' => $docs, 'categories' => TaxDocument::CATEGORIES, 'category' => $category,
            'correspondence' => TaxCorrespondence::query()->forContext($this->ctx())->orderByDesc('occurred_on')->limit(50)->get(),
            'needs' => $needs,
            'deadlines' => TaxDeadline::query()->forContext($this->ctx())->orderByDesc('due_date')->limit(40)->get(),
            'canManage' => $this->can(Capability::PrepareTaxReturn),
            'today' => CarbonImmutable::today(),
        ]);
    }

    public function store(Request $request, TaxDocuments $documents): RedirectResponse
    {
        $this->require(Capability::PrepareTaxReturn);
        $data = $request->validate([
            'category' => ['required', Rule::in(array_keys(TaxDocument::CATEGORIES))],
            'title' => ['required', 'string', 'max:160'],
            'period_label' => ['nullable', 'string', 'max:60'],
            'document_date' => ['nullable', 'date_format:Y-m-d'],
            'tax_deadline_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:500'],
            'legal_hold' => ['nullable', 'boolean'],
            'file' => ['nullable', 'file', 'max:15360'],
        ]);

        if (! empty($data['tax_deadline_id'])) {
            abort_if(TaxDeadline::query()->find($data['tax_deadline_id']) === null, 404);
        }
        $data['legal_hold'] = $request->boolean('legal_hold');

        try {
            $documents->add($this->ctx(), $data, $request->file('file'), $request->user());
        } catch (TaxException|AttachmentRejected|DuplicateAttachment $e) {
            return back()->withInput()->withErrors(['document' => $e->getMessage()]);
        }

        return redirect()->route('tax.documents.index')->with('status', 'Filed in the vault, with the date it may be discarded.');
    }

    public function hold(Request $request, TaxDocument $taxDocument, TaxDocuments $documents): RedirectResponse
    {
        $this->require(Capability::PrepareTaxReturn);
        $documents->setLegalHold($this->ctx(), $taxDocument, $request->boolean('hold'), $request->user());

        return back()->with('status', $request->boolean('hold') ? 'Legal hold set: this record cannot be archived.' : 'Legal hold cleared.');
    }

    public function archive(Request $request, TaxDocument $taxDocument, TaxDocuments $documents): RedirectResponse
    {
        $this->require(Capability::PrepareTaxReturn);

        try {
            $documents->archive($this->ctx(), $taxDocument, $request->user());
        } catch (TaxException $e) {
            return back()->withErrors(['document' => $e->getMessage()]);
        }

        return back()->with('status', 'Archived. The record and its file are kept; it just leaves the active list.');
    }

    public function file(TaxDocument $taxDocument, TaxDocuments $documents, Attachments $attachments): RedirectResponse
    {
        $this->access();
        $attachment = $documents->attachmentFor($this->ctx(), $taxDocument);
        abort_if($attachment === null, 404);
        $this->authorize('view', $attachment);

        return redirect()->away($attachments->url($attachment));
    }

    public function correspond(Request $request, TaxDocuments $documents): RedirectResponse
    {
        $this->require(Capability::PrepareTaxReturn);
        $data = $request->validate([
            'direction' => ['required', Rule::in(['received', 'sent'])],
            'channel' => ['required', Rule::in(['efiling', 'email', 'letter', 'phone', 'branch', 'other'])],
            'reference' => ['nullable', 'string', 'max:80'],
            'subject' => ['required', 'string', 'max:200'],
            'summary' => ['nullable', 'string', 'max:3000'],
            'occurred_on' => ['required', 'date_format:Y-m-d'],
            'response_due_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:occurred_on'],
            'file' => ['nullable', 'file', 'max:15360'],
        ]);

        try {
            $documents->logCorrespondence($this->ctx(), $data, $request->file('file'), $request->user());
        } catch (TaxException|AttachmentRejected|DuplicateAttachment $e) {
            return back()->withInput()->withErrors(['correspondence' => $e->getMessage()]);
        }

        return back()->with('status', 'Logged.');
    }

    public function correspondenceStatus(Request $request, TaxCorrespondence $taxCorrespondence, TaxDocuments $documents): RedirectResponse
    {
        $this->require(Capability::PrepareTaxReturn);
        $data = $request->validate(['status' => ['required', Rule::in(['open', 'responded', 'closed'])]]);
        $documents->setCorrespondenceStatus($this->ctx(), $taxCorrespondence, $data['status'], $request->user());

        return back()->with('status', 'Updated.');
    }
}
