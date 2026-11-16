<?php

namespace App\Services\Paystack;

use App\Models\Licences\PaystackPlan;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Paystack plan amounts are fixed, so each licence batch's amount becomes its own plan, created once per exact
 * (amount_minor, currency, interval) and cached in `paystack_plans` so the same pair never creates a second plan.
 * Plans are never updated here (an update could reprice every subscriber on it).
 */
final class PlanRegistry
{
    public const MONTHLY = 'monthly';

    public const ANNUALLY = 'annually';

    public function __construct(private readonly PaystackGateway $gateway) {}

    /** Accepts InfoDot's wording and Paystack's: month/monthly, year/yearly/annual/annually. */
    public static function interval(string $interval): string
    {
        return match (strtolower(trim($interval))) {
            'month', 'monthly' => self::MONTHLY,
            'year', 'yearly', 'annual', 'annually' => self::ANNUALLY,
            default => throw new InvalidArgumentException('Unknown interval.'),
        };
    }

    public function forAmount(int $amountMinor, string $currency, string $interval): PaystackPlan
    {
        $currency = strtoupper($currency);
        $interval = self::interval($interval);

        if ($amountMinor < 1) {
            throw new InvalidArgumentException('A plan needs a positive amount.');
        }

        $find = fn () => PaystackPlan::query()->where(['amount_minor' => $amountMinor, 'currency' => $currency, 'interval' => $interval])->first();

        if (($plan = $find()) !== null) {
            return $plan;
        }

        // One creator at a time per pair, so two requests cannot both call Paystack.
        return Cache::lock('paystack-plan:'.$amountMinor.':'.$currency.':'.$interval, 30)->block(10, function () use ($find, $amountMinor, $currency, $interval) {
            if (($plan = $find()) !== null) {
                return $plan;
            }

            $name = 'InfoDot licences '.$interval.' '.$currency.' '.number_format($amountMinor / 100, 2, '.', '');
            $created = $this->gateway->createPlan($name, $amountMinor, $currency, $interval);

            try {
                return PaystackPlan::query()->create(['amount_minor' => $amountMinor, 'currency' => $currency, 'interval' => $interval, 'plan_code' => $created['plan_code'], 'name' => $name]);
            } catch (UniqueConstraintViolationException) {
                return $find() ?? throw new PaystackException('Plan cache is inconsistent.');
            }
        });
    }
}
