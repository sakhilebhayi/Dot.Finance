<?php

namespace App\Services\Intelligence;

use App\Enums\Capability;
use App\Models\Intelligence\FinanceInsight;
use App\Models\User;
use App\Services\Intelligence\Detectors\Detector;
use App\Services\Intelligence\Detectors\Finding;
use App\Support\FinanceAudit;
use App\Support\Ledger\LedgerAccess;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Runs the detectors over one ledger and keeps the findings table in step with what is true now.
 *
 * A finding is one row per (kind, subject, period). Seen again: its numbers are refreshed and a person's
 * dismiss or snooze is respected. Not seen again within its window: marked resolved (it stopped being true).
 * A detector that throws is skipped and logged so one broken rule cannot hide the others. Running a scan twice
 * in a row changes nothing the second time.
 */
final class InsightEngine
{
    /** @param iterable<Detector> $detectors */
    public function __construct(private readonly iterable $detectors) {}

    /**
     * @return array{created: list<FinanceInsight>, updated: int, resolved: int}
     */
    public function scan(LedgerContext $context, ?CarbonImmutable $asOf = null): array
    {
        $asOf = ($asOf ?? CarbonImmutable::now())->startOfDay();

        return LedgerContext::run($context, function () use ($context, $asOf) {
            $created = [];
            $updated = 0;
            $resolved = 0;

            foreach ($this->detectors as $detector) {
                try {
                    $findings = $detector->detect($context, $asOf);
                } catch (\Throwable $e) {
                    report($e);
                    Log::warning('A detector failed and was skipped.', ['kind' => $detector->kind(), 'error' => class_basename($e)]);

                    continue;
                }

                DB::transaction(function () use ($context, $detector, $asOf, $findings, &$created, &$updated, &$resolved) {
                    $seen = [];

                    foreach ($findings as $finding) {
                        $seen[$finding->subjectKey.'|'.$finding->periodKey] = true;
                        $row = FinanceInsight::query()->forContext($context)
                            ->where('kind', $finding->kind)->where('subject_key', $finding->subjectKey)->where('period_key', $finding->periodKey)->first();

                        if ($row === null) {
                            $created[] = FinanceInsight::create($context->ownerAttributes() + $this->attributes($finding) + [
                                'status' => 'open', 'first_seen_at' => now(), 'last_seen_at' => now(),
                            ]);

                            continue;
                        }

                        $row->forceFill($this->attributes($finding) + ['last_seen_at' => now()]);
                        // Back again after it had stopped being true: it is a live finding again. A dismissal stays.
                        if ($row->status === 'resolved') {
                            $row->status = 'open';
                        }
                        $row->save();
                        $updated++;
                    }

                    $window = $detector->period($asOf);
                    $stale = FinanceInsight::query()->forContext($context)->where('kind', $detector->kind())->where('period_key', $window)
                        ->whereIn('status', ['open', 'snoozed'])->get()
                        ->filter(fn (FinanceInsight $f) => ! isset($seen[$f->subject_key.'|'.$f->period_key]));

                    foreach ($stale as $row) {
                        $row->forceFill(['status' => 'resolved'])->save();
                        $resolved++;
                    }
                });
            }

            return ['created' => $created, 'updated' => $updated, 'resolved' => $resolved];
        });
    }

    public function dismiss(FinanceInsight $insight, User $by): FinanceInsight
    {
        $this->authorise($insight, $by);
        $insight->forceFill(['status' => 'dismissed', 'handled_by' => $by->id, 'handled_at' => now(), 'snoozed_until' => null])->save();
        FinanceAudit::record($insight->ledgerOwner(), 'dismissed', 'finance_insight', $insight->id, $insight->auditLabel(), null, null, null, $by->id);

        return $insight;
    }

    public function snooze(FinanceInsight $insight, User $by, int $days): FinanceInsight
    {
        $this->authorise($insight, $by);
        $days = max(1, min(90, $days));
        $insight->forceFill(['status' => 'snoozed', 'snoozed_until' => now()->addDays($days)->toDateString(), 'handled_by' => $by->id, 'handled_at' => now()])->save();
        FinanceAudit::record($insight->ledgerOwner(), 'snoozed', 'finance_insight', $insight->id, $insight->auditLabel(), ['days' => ['after' => $days]], null, null, $by->id);

        return $insight;
    }

    private function authorise(FinanceInsight $insight, User $by): void
    {
        if (! LedgerAccess::allows(Capability::RecordEntries, $by, $insight->ledgerContext())) {
            throw new AuthorizationException('You may not change findings in this ledger.');
        }
    }

    /** @return array<string, mixed> */
    private function attributes(Finding $f): array
    {
        return [
            'kind' => $f->kind, 'subject_key' => $f->subjectKey, 'period_key' => $f->periodKey, 'severity' => $f->severity,
            'title' => mb_substr($f->title, 0, 200), 'body' => $f->body, 'evidence' => $f->evidence, 'href' => $f->href ? mb_substr($f->href, 0, 300) : null,
            'basis' => 'calculated',
        ];
    }
}
