<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Services\Integrations\IntegrationStatus;
use Illuminate\View\View;

/** Which other Dot platforms this one is connected to, honestly: connected, contract only, or not possible, and why. */
class IntegrationsController extends Controller
{
    public function index(IntegrationStatus $status): View
    {
        $this->ledger();

        return view('integrations.index', ['integrations' => $status->all()]);
    }
}
