<?php

namespace App\Console\Commands;

use App\Models\AiInsight;
use App\Models\User;
use App\Services\ReserveRunwayCalculator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('finance:generate-insights')]
#[Description('Generate a weekly AI Insight per user from their real reserve-runway figures')]
class GenerateInsights extends Command
{
    private const CRITICAL_MONTHS_THRESHOLD = 3;

    public function handle(): int
    {
        $calculator = new ReserveRunwayCalculator;

        foreach (User::all() as $user) {
            $result = $calculator->calculate($user->id);

            if (! $result['available']) {
                continue;
            }

            if ($result['is_growing'] || $result['months'] >= self::CRITICAL_MONTHS_THRESHOLD) {
                AiInsight::create([
                    'user_id' => $user->id,
                    'type' => 'reserve_runway_healthy',
                    'insight' => $this->healthyInsightText($result),
                ]);

                continue;
            }

            AiInsight::create([
                'user_id' => $user->id,
                'type' => 'reserve_runway_warning',
                'insight' => $this->warningInsightText($result),
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{liquid_reserves: float, avg_monthly_burn: float, months: int|null, is_growing: bool}  $result
     */
    private function healthyInsightText(array $result): string
    {
        if ($result['is_growing']) {
            return sprintf(
                'Your liquid reserves are growing rather than shrinking: your net position added to your reserves by an average of $%s per month over your recent transaction history, on top of $%s currently held.',
                number_format(abs($result['avg_monthly_burn']), 2),
                number_format($result['liquid_reserves'], 2)
            );
        }

        return sprintf(
            'Your liquid reserves would last about %d month%s at your current spending pace -- a comfortable runway.',
            $result['months'],
            $result['months'] === 1 ? '' : 's'
        );
    }

    /**
     * @param  array{liquid_reserves: float, avg_monthly_burn: float, months: int|null, what_if: array{category: string, months_without_category: int}|null}  $result
     */
    private function warningInsightText(array $result): string
    {
        $text = sprintf(
            'At your current spending pace of $%s/month, your liquid reserves of $%s would run out in about %d month%s.',
            number_format($result['avg_monthly_burn'], 2),
            number_format($result['liquid_reserves'], 2),
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
