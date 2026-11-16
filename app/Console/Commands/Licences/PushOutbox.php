<?php

namespace App\Console\Commands\Licences;

use App\Models\Licences\LicenceOutboxEvent;
use App\Services\Licences\LicenceOutbox;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Sends every confirmation to InfoDot that is due (the safety net under the queue), and shows what is waiting.
 * Exits non-zero while any row is dead or rejected, so the scheduler watchdog reports it. Never deletes a row.
 */
#[Signature('licences:push-outbox {--status : Only list the rows that are not delivered} {--requeue : Put dead and rejected rows back in the queue} {--event= : Limit --requeue to one event_id}')]
#[Description('Deliver due licence confirmations to InfoDot, list undelivered ones, or requeue failed ones')]
class PushOutbox extends Command
{
    public function handle(LicenceOutbox $outbox): int
    {
        if ($this->option('requeue')) {
            $this->info($outbox->requeue($this->option('event') ?: null).' row(s) put back in the queue.');
        }

        if (! $this->option('status')) {
            $this->line('Attempted '.$outbox->sweep().' due row(s).');
        }

        $open = LicenceOutboxEvent::query()->where('status', '<>', LicenceOutboxEvent::SENT)->orderBy('id')->get();
        $this->line('Outbox: '.json_encode($outbox->counts() ?: ['empty' => 0]));

        if ($open->isNotEmpty()) {
            $this->table(['event_id', 'type', 'status', 'attempts', 'http', 'last error', 'next try'], $open->map(fn ($e) => [
                $e->event_id, $e->type, $e->status, $e->attempts, $e->last_http_status, $e->last_error, $e->next_attempt_at?->toDateTimeString(),
            ])->all());
        }

        return $open->whereIn('status', [LicenceOutboxEvent::DEAD, LicenceOutboxEvent::REJECTED])->isEmpty() ? self::SUCCESS : self::FAILURE;
    }
}
