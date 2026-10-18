<?php

namespace App\Console\Commands;

use App\Models\AiInsight;
use App\Models\User;
use App\Services\ReserveRunwayCalculator;
use App\Support\Money;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('finance:generate-insights')]
#[Description('Generate a weekly rule-based summary per user from their ledger reserve-runway figures')]
class GenerateInsights extends Command
{
    private const CRITICAL_MONTHS_THRESHOLD = 3;

    public function handle(): int
    {
        $calculator = new ReserveRunwayCalculator;

        foreach (User::all() as $user) {
            $sentences = [];
            $warning = false;

            foreach ($calculator->calculateAll($user->id) as $result) {
                if (! $result['available']) {
                    continue;
                }

                if ($result['is_growing'] || $result['months'] >= self::CRITICAL_MONTHS_THRESHOLD) {
                    $sentences[] = $this->healthyInsightText($result);

                    continue;
                }

                $warning = true;
                $sentences[] = $this->warningInsightText($result);
            }

            if ($sentences === []) {
                continue;
            }

            AiInsight::create([
                'user_id' => $user->id,
                'type' => $warning ? 'reserve_runway_warning' : 'reserve_runway_healthy',
                'insight' => implode(' ', $sentences),
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{currency: string, liquid_reserves_cents: int, avg_monthly_burn_cents: int, months: int|null, is_growing: bool}  $result
     */
    private function healthyInsightText(array $result): string
    {
        $currency = $result['currency'];

        if ($result['is_growing']) {
            return sprintf(
                'Your liquid %s reserves are growing rather than shrinking: your net position added to your reserves by an average of %s per month over your recent transaction history, on top of %s currently held.',
                $currency,
                Money::format(abs($result['avg_monthly_burn_cents']), $currency),
                Money::format($result['liquid_reserves_cents'], $currency)
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
    private function warningInsightText(array $result): string
    {
        $currency = $result['currency'];

        $text = sprintf(
            'At your current %s spending pace of %s/month, your liquid reserves of %s would run out in about %d month%s.',
            $currency,
            Money::format($result['avg_monthly_burn_cents'], $currency),
            Money::format($result['liquid_reserves_cents'], $currency),
            $result['months'],
            $result['months'] === 1 ? '' : 's'
        );

        if ($result['what_if'] !== null) {
            $text .= sprintf(
                ' Cutting your %s spending would extend that to about %d month%s.',
                $result['what_if']['category'],
                $result['what_if']['months_without_category'],
                $result['what_if']['months_without_category'] === 1 ? '' : 's'
            );
        }

        return $text;
    }
}
