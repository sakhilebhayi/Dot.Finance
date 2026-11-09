<?php

namespace App\Console\Commands\Gl;

use App\Services\Accounting\GlSweeper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('finance:gl:post-pending {--ledger= : Only this ledger (personal:ID or company:ID)} {--dry-run : Report what is pending without posting}')]
#[Description('Post cash-ledger rows the journals do not reflect yet (the safety net behind explicit posting)')]
class PostPending extends Command
{
    use Concerns;

    public function handle(GlSweeper $sweeper): int
    {
        $ledgers = $this->ledgers($this->option('ledger'), onlyEnabled: true);

        if ($ledgers === null) {
            return self::FAILURE;
        }

        $failed = false;

        foreach ($ledgers as $context) {
            if ($this->option('dry-run')) {
                $p = \App\Support\Ledger\LedgerContext::run($context, fn () => $sweeper->pending($context));
                $this->line(sprintf('%s: %d transaction(s), %d transfer(s), %d opening balance(s) pending.', $context->key(), count($p['transactions']), count($p['transfers']), count($p['openings'])));

                continue;
            }

            $r = $sweeper->run($context);
            $this->line(sprintf('%s: posted %d, reposted %d, reversed %d, unchanged %d.', $context->key(), $r['posted'], $r['reposted'], $r['reversed'], $r['unchanged']));

            foreach ($r['deferred'] as $why) {
                $this->warn("  deferred: {$why}");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
