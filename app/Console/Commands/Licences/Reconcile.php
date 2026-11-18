<?php

namespace App\Console\Commands\Licences;

use App\Services\Licences\LicenceReconciler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Read-only. Reports where Finance's licence records and InfoDot's differ. Exit 1 when anything differs. */
#[Signature('licences:reconcile {--team= : Only this InfoDot company uuid} {--json : Machine-readable output}')]
#[Description('Compare Finance licence records with InfoDot and report differences (changes nothing)')]
class Reconcile extends Command
{
    public function handle(LicenceReconciler $reconciler): int
    {
        $rows = $reconciler->run($this->option('team') ?: null);

        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_UNESCAPED_SLASHES));
        } elseif ($rows === []) {
            $this->info('Finance and InfoDot agree: nothing to report.');
        } else {
            $this->table(['type', 'batch', 'detail'], array_map(fn ($r) => [$r['type'], $r['batch_uuid'] ?? '-', $r['detail']], $rows));
        }

        return $rows === [] ? self::SUCCESS : self::FAILURE;
    }
}
