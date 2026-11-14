<?php

namespace App\Services\Integrations;

use App\Services\Integrations\Analytics\AnalyticsClient;

/**
 * The honest list of what Dot.Finance is connected to. Statuses:
 *
 *   connected      a real, signed channel exists on both sides and this side is live in code. "Configured" says whether
 *                  its settings are present on THIS server; unconfigured means inert, not broken.
 *   contract_only  the contract is written and this side is built, but the other platform has nothing that calls or
 *                  answers it yet. Nothing flows.
 *   not_possible   the other platform exposes nothing to build against, or there is no sound use for one.
 *
 * Mirrors docs/integrations/README.md, which has the reasons and the owner steps. A test keeps the two in step.
 */
final class IntegrationStatus
{
    public function __construct(private readonly AnalyticsClient $analytics, private readonly NotifyClient $notify) {}

    /** @return list<array{key: string, name: string, status: string, configured: bool|null, summary: string, owner_step: string}> */
    public function all(): array
    {
        return [
            ['key' => 'infodot-sso', 'name' => 'InfoDot sign-in and companies', 'status' => 'connected', 'configured' => filled(config('ecosystem.infodot.secret')),
                'summary' => 'People sign in through InfoDot; companies and roles follow InfoDot at each sign-in.',
                'owner_step' => 'Set INFODOT_SERVICE_SECRET to the value of ECOSYSTEM_SECRET_FINANCE in InfoDot.'],
            ['key' => 'infodot-membership', 'name' => 'InfoDot membership changes', 'status' => 'connected', 'configured' => filled(config('ecosystem.infodot.secret')),
                'summary' => 'InfoDot can tell this app at once that someone was removed or their role changed (POST /api/ecosystem/membership, signed).',
                'owner_step' => 'Add "finance" to ECOSYSTEM_MEMBERSHIP_PLATFORMS in InfoDot (it sends to "files,docs" by default).'],
            ['key' => 'notify', 'name' => 'Dot.Notify', 'status' => 'connected', 'configured' => $this->notify->enabled(),
                'summary' => 'Forwards notifications to Dot.Notify\'s inbound webhook, signed. Needs a webhook, event map and rule created in Dot.Notify.',
                'owner_step' => 'Create the webhook, event map and rule in Dot.Notify, then set NOTIFY_WEBHOOK_URL and NOTIFY_WEBHOOK_SECRET.'],
            ['key' => 'analytics', 'name' => 'Dot.Analytics', 'status' => 'contract_only', 'configured' => $this->analytics->enabled(),
                'summary' => 'Publishes a daily aggregate snapshot per company. Dot.Analytics does not list "finance" in its metric catalogue yet, so it refuses the call until it does.',
                'owner_step' => 'Add the "finance" platform and the metric keys from docs/integrations/analytics-finance-metrics.md to Dot.Analytics, set ANALYTICS_INGEST_SECRET_FINANCE there, then set ANALYTICS_INGEST_URL and ANALYTICS_INGEST_SECRET here.'],
            ['key' => 'emall', 'name' => 'Dot.Emall', 'status' => 'contract_only', 'configured' => filled(config('integrations.emall.secret')),
                'summary' => 'A signed endpoint is ready to receive seller sales and fees from Emall and stage them for review. Emall has no seller fees, payouts or export yet, so nothing calls it.',
                'owner_step' => 'Build the sender in Dot.Emall to the contract in docs/integrations/emall-sales-contract.md, then set FINANCE_EMALL_SECRET on both sides.'],
            ['key' => 'hr', 'name' => 'Dot.HR payroll', 'status' => 'contract_only', 'configured' => null,
                'summary' => 'The payroll summary contract is written and validated here. Dot.HR has no payroll tables or API, so nothing sends it.',
                'owner_step' => 'Dot.HR must build payroll first; the Tax module\'s PayrollImport then implements the sink.'],
            ['key' => 'projects', 'name' => 'Dot.Projects', 'status' => 'not_possible', 'configured' => null,
                'summary' => 'Dot.Projects exposes no API. Project dimensions are typed on journal lines here.', 'owner_step' => 'None until Dot.Projects publishes a project list endpoint.'],
            ['key' => 'files', 'name' => 'Dot.Files', 'status' => 'not_possible', 'configured' => null,
                'summary' => 'Dot.Files has signed membership and search endpoints but no file upload or storage API, so attachments stay on this app\'s private disk.', 'owner_step' => 'None until Dot.Files offers a storage API.'],
            ['key' => 'engage', 'name' => 'Dot.Engage', 'status' => 'not_possible', 'configured' => null,
                'summary' => 'Engage\'s API serves signatures, contract PDFs and video tokens; none is a finance use.', 'owner_step' => 'None.'],
            ['key' => 'agents', 'name' => 'Dot.Agents', 'status' => 'not_possible', 'configured' => null,
                'summary' => 'Agents runs its own skills with its own approvals. Letting an agent act on the books would bypass this app\'s controls, so it is deliberately not connected.', 'owner_step' => 'None.'],
            ['key' => 'brain', 'name' => 'Dot.Brain and Dot.Memory', 'status' => 'not_possible', 'configured' => null,
                'summary' => 'Both take events from platforms with their own tokens. No finance event has been agreed, and finance data is not sent to a shared memory without an explicit decision.', 'owner_step' => 'Decide which aggregate events (if any) should be recorded there.'],
        ];
    }
}
