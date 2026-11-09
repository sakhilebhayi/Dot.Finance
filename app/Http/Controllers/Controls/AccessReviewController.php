<?php

namespace App\Http\Controllers\Controls;

use App\Enums\Capability;
use App\Models\Controls\AccessReview;
use App\Services\Controls\AccessReport;
use App\Services\Controls\AccessReviews;
use App\Support\ExportAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/** The user access review: who has what, last seen, grant history, CSV, and the recorded confirmation. */
class AccessReviewController extends ControlsBaseController
{
    public function index(AccessReport $report, AccessReviews $reviews): View
    {
        $access = $this->authorizeAny(Capability::ViewAudit, Capability::ManageMembers);
        $context = $this->ledger();

        return view('controls.access-review', [
            'rows' => $report->rows($context),
            'latest' => $reviews->latest($context),
            'past' => AccessReview::query()->with('reviewer:id,name')->latest('reviewed_at')->limit(20)->get(),
            'canConfirm' => $access->can(Capability::ManageMembers),
            'canExport' => $access->can(Capability::ExportReports) && $access->can(Capability::ViewAudit),
        ]);
    }

    public function store(Request $request, AccessReviews $reviews): RedirectResponse
    {
        $this->authorizeAny(Capability::ManageMembers);
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);
        $context = $this->ledger();

        return $this->attempt(fn () => $reviews->record($request->user(), $context, $data['notes'] ?? null), 'Access review recorded.');
    }

    public function export(AccessReport $report): Response
    {
        $this->authorizeAny(Capability::ViewAudit);
        $this->authorizeAny(Capability::ExportReports);
        $context = $this->ledger();
        ExportAudit::record('Access review (who can do what)');

        return response($report->csv($context), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="access-review-'.now()->toDateString().'.csv"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
