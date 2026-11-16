<?php

namespace App\Services\Licences;

use App\Services\Paystack\PlanRegistry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class LicencePeriods
{
    public static function end(CarbonInterface $start, string $interval): CarbonImmutable
    {
        $start = CarbonImmutable::instance($start);

        return PlanRegistry::interval($interval) === PlanRegistry::ANNUALLY ? $start->addYearNoOverflow() : $start->addMonthNoOverflow();
    }
}
