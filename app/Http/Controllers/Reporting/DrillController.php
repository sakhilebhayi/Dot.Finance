<?php

namespace App\Http\Controllers\Reporting;

use App\Models\Accounting\GlAccount;
use App\Services\Accounting\GlSync;
use App\Services\Reporting\DrillDown;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The journal lines behind a report figure: /reports/drill?account=&from=&to=. */
class DrillController extends ReportingController
{
    public function __invoke(Request $request, DrillDown $drill, GlSync $sync): View
    {
        $this->access();
        $context = $this->ledger();

        $f = $request->validate([
            'account' => ['nullable', 'integer', 'min:1'],
            'class' => ['nullable', 'string', 'in:'.implode(',', DrillDown::CLASSES)],
            'subtype' => ['nullable', 'string', 'max:40', 'alpha_dash'],
            'not_subtype' => ['nullable', 'string', 'max:40', 'alpha_dash'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'basis' => ['nullable', 'in:accrual,cash'],
            'party' => ['nullable', 'regex:/^(\d{1,12}|none)$/'],
            'category' => ['nullable', 'regex:/^(\d{1,12}|none)$/'],
            'dimension' => ['nullable', 'string', 'max:80'],
            'tax_code' => ['nullable', 'integer', 'min:1'],
            'noclose' => ['nullable', 'in:0,1'],
            'noclose_from' => ['nullable', 'date_format:Y-m-d'],
            'cash_entries' => ['nullable', 'in:0,1'],
            'bs_group' => ['nullable', 'in:current,noncurrent'],
            'sign' => ['nullable', 'in:credit,debit'],
            'figure' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        // A foreign or unknown account id is "not found", never "ignore the filter and show everything".
        if (isset($f['account']) && ! GlAccount::query()->whereKey((int) $f['account'])->exists()) {
            abort(404);
        }

        $result = $drill->lines($context, $f, (int) ($f['page'] ?? 1));

        return view('reports.drill', [
            'result' => $result, 'filters' => $f, 'currency' => $sync->settings($context)?->base_currency,
            'figure' => isset($f['figure']) ? (int) $f['figure'] : null,
        ]);
    }
}
