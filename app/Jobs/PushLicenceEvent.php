<?php

namespace App\Jobs;

use App\Services\Licences\LicenceOutbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Tries one outbox row once, off the request path. Retries are the outbox's own (next_attempt_at plus the sweep command),
 * so this job never fails or loops: a failure is written on the row, where it is visible, and the sweep picks it up when due.
 */
class PushLicenceEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $outboxId) {}

    public function handle(LicenceOutbox $outbox): void
    {
        $outbox->deliver($this->outboxId);
    }
}
