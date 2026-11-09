<?php

namespace App\Services\Intelligence\Detectors;

use App\Models\FinanceAuditLog;
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

        return $findings;
    }
}
