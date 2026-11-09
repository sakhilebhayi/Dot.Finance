<?php

namespace App\Console\Commands;

use App\Services\Integrations\Analytics\AnalyticsClient;
use App\Services\Integrations\Analytics\SnapshotPublisher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('finance:publish-metrics {--dry-run : Print the payloads that would be sent; send and queue nothing}')]
#[Description('Queue each company ledger\'s daily aggregate snapshot for Dot.Analytics (inert until the url and secret are set)')]
class PublishMetrics extends Command
{
    public function handle(SnapshotPublisher $publisher, AnalyticsClient $client): int
    {
        try {
            if ($this->option('dry-run')) {
                $payloads = $publisher->publishAll(dryRun: true);
                $this->line((string) json_encode($payloads->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $this->line($client->enabled() ? 'Dry run: nothing was sent or queued.' : 'Dry run: nothing was sent or queued, and with the current settings it would not be sent (ANALYTICS_INGEST_URL and ANALYTICS_INGEST_SECRET must both be set).');

                return self::SUCCESS;
            }

            if (! $client->enabled()) {
                $this->line('Publishing to Dot.Analytics is not configured (ANALYTICS_INGEST_URL and ANALYTICS_INGEST_SECRET must both be set). Nothing sent.');

                return self::SUCCESS;
            }

            $this->line('Queued '.$publisher->publishAll()->count().' company snapshot(s).');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Could not compute the snapshots: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
