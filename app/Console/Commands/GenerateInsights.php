<?php

namespace App\Console\Commands;

use App\Models\Insight;
use App\Services\ReserveRunwayCalculator;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;

#[Signature('finance:generate-insights')]
#[Description('Write each ledger\'s weekly rule-based summary (plain arithmetic on a person\'s or a company\'s ledger, not AI); at most one per ledger per ISO week')]
class GenerateInsights extends Command
{
    private const CRITICAL_MONTHS_THRESHOLD = 3;

    public function handle(): int
    {
        $calculator = new ReserveRunwayCalculator;
        $week = now()->format('o-\\WW');

        // Every ledger that has an account: each person's personal ledger and each company's.
        // There is no signed-in person here, so each one is named explicitly.
        $contexts = LedgerContext::owningRowsIn('accounts', liveOnly: true)
            ->filter(fn (LedgerContext $context) => ! $context->isCompany() || $context->team()?->isCompany());

        foreach ($contexts as $context) {
            LedgerContext::run($context, fn () => $this->summarise($context, $calculator, $week));
        }

        return self::SUCCESS;
    }

    private function summarise(LedgerContext $context, ReserveRunwayCalculator $calculator, string $week): void
    {
        // One summary per ledger per ISO week: a second run, a retry or a
        // manual run in the same week leaves the existing one alone.
        if (Insight::query()->forContext($context)->where('iso_week', $week)->exists()) {
            return;
        }

        $sentences = [];
        $warning = false;
        // A company's summary talks about the company; a person's talks to the person, worded exactly as it always was.
        $company = $context->isCompany() ? $context->label() : null;

        foreach ($calculator->calculateAll($context) as $result) {
            if (! $result['available']) {
                continue;
            }

            if ($result['is_growing'] || $result['months'] >= self::CRITICAL_MONTHS_THRESHOLD) {
                $sentences[] = $this->healthyInsightText($result, $company);

                continue;
            }

            $warning = true;
            $sentences[] = $this->warningInsightText($result, $company);
        }

        if ($sentences === []) {
            return;
        }

        try {
            Insight::create($context->ownerAttributes() + [
                'type' => $warning ? 'reserve_runway_warning' : 'reserve_runway_healthy',
                'insight' => implode(' ', $sentences),
                'iso_week' => $week,
            ]);
        } catch (UniqueConstraintViolationException) {
            // another run wrote this week's summary between the check and the insert
        }
    }

    /**
     * @param  array{currency: string, liquid_reserves_cents: int, avg_monthly_burn_cents: int, months: int|null, is_growing: bool}  $result
     */
    private function healthyInsightText(array $result, ?string $company = null): string
    {
        $currency = $result['currency'];

        if ($result['is_growing']) {
            if ($company !== null) {
                return sprintf(
                    "%s's liquid %s reserves are growing rather than shrinking: its net position added to its reserves by an average of %s per month over its recent transaction history, on top of %s currently held.",
                    $company,
                    $currency,
                    Money::format(abs($result['avg_monthly_burn_cents']), $currency),
                    Money::format($result['liquid_reserves_cents'], $currency)
                );
            }

            return sprintf(
                'Your liquid %s reserves are growing rather than shrinking: your net position added to your reserves by an average of %s per month over your recent transaction history, on top of %s currently held.',
                $currency,
                Money::format(abs($result['avg_monthly_burn_cents']), $currency),
                Money::format($result['liquid_reserves_cents'], $currency)
            );
        }

        if ($company !== null) {
            return sprintf(
                "%s's liquid %s reserves would last about %d month%s at its current spending pace -- a comfortable runway.",
                $company,
                $currency,
                $result['months'],
                $result['months'] === 1 ? '' : 's'
            );
        }

        return sprintf(
            'Your liquid %s reserves would last about %d month%s at your current spending pace -- a comfortable runway.',
            $currency,
            $result['months'],
            $result['months'] === 1 ? '' : 's'
        );
    }

    /**
     * @param  array{currency: string, liquid_reserves_cents: int, avg_monthly_burn_cents: int, months: int|null, what_if: array{category: string, months_without_category: int}|null}  $result
     */
    private function warningInsightText(array $result, ?string $company = null): string
    {
        $currency = $result['currency'];

        $text = $company !== null
            ? sprintf(
                "At %s's current %s spending pace of %s/month, its liquid reserves of %s would run out in about %d month%s.",
                $company,
                $currency,
                Money::format($result['avg_monthly_burn_cents'], $currency),
                Money::format($result['liquid_reserves_cents'], $currency),
                $result['months'],
                $result['months'] === 1 ? '' : 's'
            )
            : sprintf(
                'At your current %s spending pace of %s/month, your liquid reserves of %s would run out in about %d month%s.',
                $currency,
                Money::format($result['avg_monthly_burn_cents'], $currency),
                Money::format($result['liquid_reserves_cents'], $currency),
                $result['months'],
                $result['months'] === 1 ? '' : 's'
            );

        if ($result['what_if'] !== null) {
            $text .= sprintf(
                $company !== null ? ' Cutting its %s spending would extend that to about %d month%s.' : ' Cutting your %s spending would extend that to about %d month%s.',
                $result['what_if']['category'],
                $result['what_if']['months_without_category'],
                $result['what_if']['months_without_category'] === 1 ? '' : 's'
            );
        }

        return $text;
    }
}
