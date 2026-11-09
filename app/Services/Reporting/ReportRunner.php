<?php

namespace App\Services\Reporting;

use App\Models\Accounting\LedgerSetting;
use App\Services\Accounting\GlSync;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Turns "this report, these parameters, this ledger" into a built ReportResult. The single path pages, exports and presets share. */
class ReportRunner
{
    public function __construct(private readonly ReportRegistry $registry, private readonly GlSync $sync) {}

    public function enabled(LedgerContext $context): bool
    {
        return $this->sync->isEnabled($context);
    }

    /** @param  array<string, mixed>  $input */
    public function request(LedgerContext $context, string $key, array $input, ?CarbonImmutable $today = null): ReportRequest
    {
        $settings = $this->sync->settings($context) ?? throw new InvalidArgumentException('Accounting is not switched on for this ledger.');
        $report = $this->registry->get($key);

        // A point-in-time report only reads the end date; a start date from an old link must not change its meaning.
        return ReportRequest::make($context, $settings, $input, $report->optionKeys(), $today);
    }

    /** @param  array<string, mixed>  $input */
    public function run(LedgerContext $context, string $key, array $input, ?CarbonImmutable $today = null): ReportResult
    {
        return $this->build($key, $this->request($context, $key, $input, $today));
    }

    public function build(string $key, ReportRequest $request): ReportResult
    {
        // Reports read through models and the ledger scope; naming the ledger makes them correct from a console or a queue too.
        return LedgerContext::run($request->context, fn () => $this->registry->get($key)->build($request));
    }

    public function settings(LedgerContext $context): ?LedgerSetting
    {
        return $this->sync->settings($context);
    }
}
