<?php

namespace App\Http\Controllers\Intelligence;

use App\Http\Controllers\Controller;
use App\Models\Intelligence\FinanceNotification;
use App\Models\Intelligence\NotificationPreference;
use App\Services\Integrations\NotifyClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** A person's own notifications in the active ledger, and which kinds they want and where. Nobody sees anyone else's. */
class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $this->ledger();

        return view('intel.notifications', [
            'notifications' => FinanceNotification::query()->for($request->user())->latest('id')->paginate(25),
            'unread' => FinanceNotification::query()->for($request->user())->unread()->count(),
        ]);
    }

    public function read(Request $request, FinanceNotification $notification): RedirectResponse
    {
        abort_unless($notification->recipient_id === $request->user()->id, 404);

        $notification->read_at ??= now();
        $notification->save();

        // A notification may point at a page; only a same-site path is followed.
        $href = (string) $notification->href;
        $path = parse_url($href, PHP_URL_PATH);
        $host = parse_url($href, PHP_URL_HOST);

        if ($request->boolean('open') && $path && ($host === null || $host === $request->getHost())) {
            return redirect($path);
        }

        return back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $this->ledger();

        FinanceNotification::query()->for($request->user())->unread()->get()->each(function (FinanceNotification $n) {
            $n->read_at = now();
            $n->save();
        });

        return back()->with('status', 'All marked as read.');
    }

    public function preferences(Request $request, NotifyClient $notify): View
    {
        $context = $this->ledger();

        $rows = NotificationPreference::query()->where('member_user_id', $request->user()->id)->get()->keyBy('kind');

        $kinds = [];
        foreach (FinanceNotification::KINDS as $kind => $label) {
            $row = $rows->get($kind);
            $kinds[$kind] = ['label' => $label] + ($row ? ['in_app' => $row->in_app, 'email' => $row->email, 'notify' => $row->notify] : NotificationPreference::DEFAULTS);
        }

        return view('intel.preferences', ['kinds' => $kinds, 'notifyConnected' => $notify->enabled(), 'ledger' => $context]);
    }

    public function update(Request $request): RedirectResponse
    {
        $context = $this->ledger();
        $input = (array) $request->input('kinds', []);

        foreach (array_keys(FinanceNotification::KINDS) as $kind) {
            $values = (array) ($input[$kind] ?? []);

            NotificationPreference::query()->forContext($context)->updateOrCreate(
                ['member_user_id' => $request->user()->id, 'kind' => $kind],
                $context->ownerAttributes() + ['in_app' => ! empty($values['in_app']), 'email' => ! empty($values['email']), 'notify' => ! empty($values['notify'])],
            );
        }

        return redirect()->route('intel.notifications.preferences')->with('status', 'Your notification choices were saved.');
    }
}
