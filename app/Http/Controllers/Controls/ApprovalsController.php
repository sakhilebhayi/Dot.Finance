<?php

namespace App\Http\Controllers\Controls;

use App\Enums\Capability;
use App\Models\Controls\ApprovalRequest;
use App\Services\Controls\ApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The approvals inbox: waiting for me, my requests, history. */
class ApprovalsController extends ControlsBaseController
{
    public function index(Request $request, ApprovalService $approvals): View
    {
        $this->authorizeAny(Capability::ViewFinance);

        $context = $this->ledger();
        $tab = in_array($request->query('tab'), ['waiting', 'mine', 'history'], true) ? $request->query('tab') : 'waiting';
        $user = $request->user();

        return view('controls.approvals', [
            'tab' => $tab,
            'waiting' => $approvals->waitingFor($user, $context),
            'mine' => ApprovalRequest::query()->where('requested_by', $user->id)->latest('id')->limit(100)->get(),
            'history' => ApprovalRequest::query()->where('status', '<>', ApprovalRequest::PENDING)->with(['requester:id,name', 'decider:id,name', 'decisions.decider:id,name'])->latest('decided_at')->latest('id')->limit(100)->get(),
        ]);
    }

    public function approve(Request $request, ApprovalRequest $approval, ApprovalService $approvals): RedirectResponse
    {
        $this->authorizeAny(Capability::ViewFinance);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return $this->attempt(fn () => $approvals->approve($request->user(), $approval, $data['reason'] ?? null), 'Approved.');
    }

    public function reject(Request $request, ApprovalRequest $approval, ApprovalService $approvals): RedirectResponse
    {
        $this->authorizeAny(Capability::ViewFinance);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->attempt(fn () => $approvals->reject($request->user(), $approval, $data['reason']), 'Rejected.');
    }

    public function cancel(Request $request, ApprovalRequest $approval, ApprovalService $approvals): RedirectResponse
    {
        $this->authorizeAny(Capability::ViewFinance);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return $this->attempt(fn () => $approvals->cancel($request->user(), $approval, $data['reason'] ?? null), 'Cancelled.');
    }
}
