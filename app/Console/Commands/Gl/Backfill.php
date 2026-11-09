<?php

namespace App\Console\Commands\Gl;

use App\Models\Accounting\GlBackfillRun;
use App\Models\Accounting\LedgerSetting;
use App\Services\Accounting\GlBackfill;
use App\Support\Ledger\LedgerContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('finance:gl:backfill {--ledger= : The ledger (personal:ID or company:ID)} {--dry-run : Compute everything, roll it back, report} {--apply : Post the history, then verify} {--resume : Resume the last interrupted apply} {--enable : After a verified apply, switch accounting on}')]
#[Description('Post a ledger\'s cash history into the journals: dry run, apply (resumable, idempotent), verify, enable')]
class Backfill extends Command
{
    use Concerns;

    public function handle(GlBackfill $backfill): int
    {
        if ($this->option('ledger') === null) {
            $this->error('Name the ledger: --ledger=personal:12 or --ledger=company:3.');

            return self::FAILURE;
        }

        $ledgers = $this->ledgers($this->option('ledger'));
        if ($ledgers === null) {
            return self::FAILURE;
        }

        $context = $ledgers->first();

        if (! $this->option('dry-run') && ! $this->option('apply') && ! $this->option('enable')) {
            $this->error('Choose --dry-run, --apply or --enable.');

            return self::FAILURE;
        }

        $settings = LedgerContext::run($context, fn () => LedgerSetting::query()->forContext($context)->first());
        if ($settings === null) {
            $this->error('This ledger has no accounting settings. Prepare it first (the accounting setup page, or GlBackfill::prepare).');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $run = $backfill->dryRun($context);
            $this->summarise($run);

            return $this->ok($run) ? self::SUCCESS : self::FAILURE;
        }

        if ($this->option('apply')) {
            $resume = $this->option('resume')
                ? LedgerContext::run($context, fn () => GlBackfillRun::query()->forContext($context)->where('mode', 'apply')->whereIn('status', ['running', 'failed'])->orderByDesc('id')->first())
                : null;

            $run = $backfill->apply($context, null, $resume);
            $this->summarise($run);

            if (! $this->ok($run)) {
                return self::FAILURE;
            }
        }

        if ($this->option('enable')) {
            LedgerContext::run($context, fn () => $backfill->enable($context));
            $this->info('Accounting is enabled for '.$context->key().'.');
        }

        return self::SUCCESS;
    }

    private function ok(GlBackfillRun $run): bool
    {
        return (bool) ($run->report['verify']['ok'] ?? false);
    }

    private function summarise(GlBackfillRun $run): void
    {
        $this->line(sprintf('Run #%d (%s): %s. %d source(s), %d entr%s posted, %d line(s).', $run->id, $run->mode, $run->status, $run->sources_total, $run->entries_posted, $run->entries_posted === 1 ? 'y' : 'ies', $run->lines_posted));

        foreach ($run->report['verify']['checks'] ?? [] as $check) {
            $line = sprintf('  [%s] %s: %s', $check['ok'] ? 'ok' : 'FAIL', $check['code'], $check['message']);
            $check['ok'] ? $this->line($line) : $this->error($line);
        }

        $exceptions = $run->exceptions ?? [];
        if ($exceptions !== []) {
            $this->warn(count($exceptions).' exception(s), not posted (the owner decides; nothing is changed automatically):');
            foreach (array_slice($exceptions, 0, 50) as $e) {
                $this->line("  - {$e['source']}: {$e['reason']}");
            }
        }
    }
}
