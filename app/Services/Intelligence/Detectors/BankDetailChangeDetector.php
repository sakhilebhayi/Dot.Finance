<?php

namespace App\Services\Intelligence\Detectors;

use App\Models\FinanceAuditLog;
use App\Models\Purchases\SupplierBankDetail;
use App\Models\Shared\Party;
use App\Models\User;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/**
 * A supplier's bank details changed: the classic payment-redirection fraud. It reads the audit log, so it sees a
 * change only if the module that stores the bank details audits it under one of the configured field names
 * (config intelligence.detectors.bank_detail_fields). The parties table has no bank fields today, so this stays
 * silent until Purchases adds them; it never shows the old or new value, only that the field changed and who changed it.
 */
final class BankDetailChangeDetector implements Detector
{
    public function kind(): string
    {
        return 'bank_details_changed';
    }

    public function period(CarbonImmutable $asOf): string
    {
        return $asOf->format('Y-m');
    }

    public function detect(LedgerContext $context, CarbonImmutable $asOf): array
    {
        $fields = (array) config('intelligence.detectors.bank_detail_fields', []);

        $logs = FinanceAuditLog::query()->forContext($context)
            ->where('subject_type', 'party')->where('action', 'updated')
            ->where('created_at', '>=', $asOf->subDays(30)->startOfDay())->orderBy('id')->get();

        $findings = [];
        foreach ($logs as $log) {
            $changed = array_values(array_intersect($fields, array_keys((array) $log->changes)));
            if ($changed === []) {
                continue;
            }

            $party = Party::query()->forContext($context)->withTrashed()->find($log->subject_id);
            $actor = $log->actor_user_id ? User::find($log->actor_user_id)?->name : null;
            $name = $party?->name ?? 'a supplier';

            $findings[] = new Finding(
                $this->kind(),
                'party:'.$log->subject_id.':'.$log->id,
                $log->created_at->format('Y-m'),
                'critical',
                "Bank details changed for {$name}",
                "The bank details of {$name} were changed on ".$log->created_at->toDateString().($actor ? " by {$actor}" : '').'. Confirm the change with the supplier on a number you already had (not one from an e-mail) before paying them again.',
                [['label' => 'Fields changed', 'value' => implode(', ', $changed)], ['label' => 'Changed by', 'value' => $actor ?? 'the system']],
                $party && fx_route_exists('parties.show') ? route('parties.show', $party->id) : null,
            );
        }

        return array_merge($findings, $this->purchasesChanges($context, $asOf));
    }

    /**
     * Purchases keeps a supplier's bank details in their own versioned table and audits each step under the subject "supplier_bank". A
     * version after the first being ACTIVATED is the change that matters (the first set is not a change). The audit row never holds the
     * details, so neither does this finding: only which version, who approved it and whether the approver was the person who asked.
     *
     * @return list<Finding>
     */
    private function purchasesChanges(LedgerContext $context, CarbonImmutable $asOf): array
    {
        $logs = FinanceAuditLog::query()->forContext($context)
            ->where('subject_type', 'supplier_bank')->where('action', 'bank_details_activated')
            ->where('created_at', '>=', $asOf->subDays(30)->startOfDay())->orderBy('id')->get();

        $findings = [];
        foreach ($logs as $log) {
            if ((int) ($log->changes['version']['after'] ?? 1) < 2) {
                continue;
            }

            $detail = SupplierBankDetail::query()->forContext($context)->find($log->subject_id);
            $party = $detail ? Party::query()->forContext($context)->withTrashed()->find($detail->party_id) : null;
            $actor = $log->actor_user_id ? User::find($log->actor_user_id)?->name : null;
            $name = $party?->name ?? 'a supplier';
            $self = (bool) ($log->changes['self_approved']['after'] ?? false);

            $findings[] = new Finding(
                $this->kind(),
                'supplier_bank:'.$log->subject_id.':'.$log->id,
                $log->created_at->format('Y-m'),
                'critical',
                "Bank details changed for {$name}",
                "The bank details of {$name} were changed on ".$log->created_at->toDateString().($actor ? " and approved by {$actor}" : '').($self ? ', by the same person who asked for the change' : '').'. Confirm the change with the supplier on a number you already had (not one from an e-mail) before paying them again.',
                [['label' => 'Version now active', 'value' => (string) ($log->changes['version']['after'] ?? '')], ['label' => 'Approved by', 'value' => $actor ?? 'the system'], ['label' => 'Asked and approved by one person', 'value' => $self ? 'Yes' : 'No']],
                $party && fx_route_exists('purchases.suppliers.show') ? route('purchases.suppliers.show', $party->id) : ($party && fx_route_exists('parties.show') ? route('parties.show', $party->id) : null),
            );
        }

        return $findings;
    }
}
