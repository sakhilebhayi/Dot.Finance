<?php

namespace App\Http\Controllers\Controls;

use App\Enums\Capability;
use App\Models\Controls\ApprovalPolicy;
use App\Services\Controls\AccessReviews;
use App\Services\Controls\ApprovalService;
use App\Support\Ledger\LedgerAccess;
use Illuminate\View\View;

/** The Controls home: what needs this person's attention and where each governance screen is. */
class ControlsIndexController extends ControlsBaseController
{
    public function __invoke(ApprovalService $approvals, AccessReviews $reviews): View
    {
        $this->authorizeAny(Capability::ViewFinance);

        $context = $this->ledger();
        $access = LedgerAccess::current(request()->user());

        return view('controls.index', [
            'access' => $access,
            'waiting' => $approvals->waitingFor(request()->user(), $context)->count(),
            'activePolicies' => ApprovalPolicy::query()->where('is_active', true)->count(),
            'lastReview' => $reviews->latest($context),
        ]);
    }
}
