<?php

namespace App\Services\Purchases;

use App\Models\Purchases\Bill;
use App\Models\Purchases\RecurringBill;
use App\Models\User;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Bills that repeat. A schedule never posts anything: each due period makes a DRAFT bill (source "recurring") that still goes through
 * duplicate checking, approval and posting like any other. It is idempotent: the pair (schedule, period) is unique in the database, so running
 * the command twice, or two servers running it at once, makes each bill once. A schedule that missed some periods catches up, at most
 * twelve at a time. A schedule whose supplier is archived, or whose lines no longer pass the checks, is skipped and says why.
 */
final class RecurringBills
{
    private const CATCH_UP = 12;

    public function __construct(private readonly BillWorkflow $bills) {}

    /** The date after $from for a schedule. */
    public static function advance(CarbonImmutable $from, string $frequency, int $interval): CarbonImmutable
    {
        $interval = max(1, $interval);

        return match ($frequency) {
            'weekly' => $from->addWeeks($interval),
            'monthly' => $from->addMonthsNoOverflow($interval),
            'quarterly' => $from->addMonthsNoOverflow(3 * $interval),
            'yearly' => $from->addYearsNoOverflow($interval),
        };
    }

    /**
     * Make the drafts that are due on or before $today for the ledger.
     *
     * @return array{created: int, skipped: list<string>}
     */
    public function generateDue(LedgerContext $context, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $created = 0;
        $skipped = [];

        LedgerContext::run($context, function () use ($context, $today, &$created, &$skipped) {
            $due = RecurringBill::query()->where('status', 'active')->where('next_run_on', '<=', $today->toDateString())->orderBy('id')->get();

            foreach ($due as $schedule) {
                $by = User::query()->find($schedule->created_by);
                if ($by === null) {
                    $skipped[] = "{$schedule->name}: the person who set it up no longer exists";

                    continue;
                }

                for ($n = 0; $n < self::CATCH_UP; $n++) {
                    $schedule->refresh();
                    $runOn = CarbonImmutable::parse($schedule->next_run_on->toDateString());

                    if ($schedule->status !== 'active' || $runOn->greaterThan($today)) {
                        break;
                    }
                    if ($schedule->end_on !== null && $runOn->greaterThan(CarbonImmutable::parse($schedule->end_on->toDateString()))) {
                        $schedule->forceFill(['status' => 'ended'])->save();
                        break;
                    }

                    try {
                        DB::transaction(function () use ($context, $by, $schedule, $runOn, &$created) {
                            if (Bill::query()->where('recurring_bill_id', $schedule->id)->where('recurring_period', $runOn->toDateString())->exists()) {
                                // made already (an earlier run, another server): just move the schedule on
                            } else {
                                $this->bills->saveDraft($context, $by, [
                                    'party_id' => $schedule->party_id, 'bill_date' => $runOn->toDateString(), 'due_date' => $runOn->addDays($schedule->days_until_due)->toDateString(),
                                    'currency' => $schedule->currency, 'tax_inclusive' => $schedule->tax_inclusive, 'document_type' => $schedule->document_type ?? 'unknown',
                                    'supplier_reference' => null, 'source' => 'recurring', 'recurring_bill_id' => $schedule->id, 'recurring_period' => $runOn->toDateString(),
                                    'lines' => $schedule->template,
                                ]);
                                $created++;
                            }

                            $schedule->forceFill([
                                'last_generated_on' => $runOn->toDateString(),
                                'next_run_on' => self::advance($runOn, $schedule->frequency, $schedule->interval_count)->toDateString(),
                            ])->save();
                        });
                    } catch (Throwable $e) {
                        $skipped[] = "{$schedule->name} ({$runOn->toDateString()}): ".($e instanceof PurchasesException ? $e->getMessage() : 'could not be made');
                        break;
                    }
                }
            }
        });

        return ['created' => $created, 'skipped' => $skipped];
    }
}
