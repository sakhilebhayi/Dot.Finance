<?php

namespace App\Http\Controllers\Sales;

use App\Models\Sales\SalesShareLink;
use App\Services\Sales\ShareLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ShareLinkController extends SalesController
{
    public function revoke(Request $request, SalesShareLink $link, ShareLinks $links): RedirectResponse
    {
        $this->allow('issue-invoice');
        $links->revoke($link, $request->user());

        return back()->with('status', 'The link no longer works.');
    }
}
