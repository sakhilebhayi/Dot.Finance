<?php

namespace App\Services\Integrations\Analytics;

use App\Jobs\SendAnalyticsSnapshot;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds today's snapshot for each COMPANY ledger and queues it. A personal ledger is never published: it has no
 * InfoDot company to file it under, and a person's private books are not an ecosystem metric.
 */
final class SnapshotPublisher
{
    public function __construct(private readonly MetricsCollector $collector, private readonly AnalyticsClient $client) {}

    /**
     * taken_at is the START of the UTC day, so every run on the same day names the same snapshot and Analytics
     * treats the repeat as a replay.
     *
     * @return array<string, mixed>|null null when the ledger is not a company
     */
    public function payload(LedgerContext $context, ?CarbonImmutable $asOf = null): ?array
    {
        $team = $context->team();

        if (! $context->isCompany() || $team === null || blank($team->infodot_team_uuid)) {
            return null;
        }

        $asOf = ($asOf ?? CarbonImmutable::now())->utc();
        $metrics = $this->collector->collect($context, $asOf);

        if ($metrics === []) {
            return null;
        }

        return [
            'platform' => (string) config('integrations.analytics.platform', 'finance'),
            'company_uuid' => strtolower((string) $team->infodot_team_uuid),
            'taken_at' => $asOf->startOfDay()->format('Y-m-d\TH:i:s\Z'),
            'period' => 'day',
            'metrics' => $metrics,
        ];
    }

    /** @return Collection<int, array<string, mixed>> the payloads (queued unless $dryRun) */
    public function publishAll(bool $dryRun = false, ?CarbonImmutable $asOf = null): Collection
    {
        if (! $dryRun && ! $this->client->enabled()) {
            return collect();
        }

        $out = collect();

        foreach (LedgerContext::owningRowsIn('accounts', liveOnly: true)->filter(fn (LedgerContext $c) => $c->isCompany() && $c->team()?->isCompany()) as $context) {
            $payload = $this->payload($context, $asOf);
            if ($payload === null) {
                continue;
            }

            $out->push($payload);

            if (! $dryRun) {
                try {
                    app(Dispatcher::class)->dispatch(new SendAnalyticsSnapshot($payload));
                } catch (Throwable $e) {
                    Log::warning('Could not queue the Dot.Analytics snapshot.', ['error' => class_basename($e)]);
                }
            }
        }

        return $out;
    }
}
