<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\Accounting\GlAccount;
use App\Models\Integrations\EmallSaleLine;
use App\Services\Integrations\Emall\EmallSalesPoster;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Review the Dot.Emall sale lines that arrived: post a clean one, or reject one. Posting needs the journal capability. */
class EmallReviewController extends Controller
{
    public function index(Request $request): View
    {
        $this->ledger();
        $status = in_array($request->query('status'), ['staged', 'needs_attention', 'posted', 'rejected'], true) ? $request->query('status') : null;

        return view('integrations.emall', [
            'lines' => EmallSaleLine::query()->when($status, fn ($q) => $q->where('status', $status))->latest('id')->paginate(25)->withQueryString(),
            'status' => $status,
            'counts' => EmallSaleLine::query()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status'),
            'expenseAccounts' => GlAccount::query()->where('class', 'expense')->where('is_active', true)->where('is_postable', true)->orderBy('code')->get(),
        ]);
    }

    public function post(Request $request, EmallSaleLine $line, EmallSalesPoster $poster): RedirectResponse
    {
        $data = $request->validate(['fee_account_id' => ['nullable', 'integer']]);

        try {
            $poster->post($line, $request->user(), isset($data['fee_account_id']) ? (int) $data['fee_account_id'] : null);
        } catch (AuthorizationException) {
            abort(403);
        }

        return back()->with('status', 'Posted to the books.');
    }

    public function reject(Request $request, EmallSaleLine $line, EmallSalesPoster $poster): RedirectResponse
    {
        try {
            $poster->reject($line, $request->user());
        } catch (AuthorizationException) {
            abort(403);
        }

        return back()->with('status', 'Rejected. It will not be posted.');
    }
}
