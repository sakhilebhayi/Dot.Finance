<?php

namespace App\Http\Controllers\Sales;

use App\Models\Sales\SalesReminder;
use Illuminate\Contracts\View\View;

/** The log of payment reminders handed to the mailer (or skipped, and why). */
class ReminderController extends SalesController
{
    public function index(): View
    {
        $this->allow('view-finance');

        return view('sales.reminders.index', ['reminders' => SalesReminder::query()->with('party', 'document')->orderByDesc('id')->paginate(30)]);
    }
}
