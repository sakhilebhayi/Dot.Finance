<?php

namespace App\Services\Reporting;

use App\Enums\ReportBasis;
use App\Models\Accounting\LedgerSetting;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;

/**
 * Everything a report needs to know about the question being asked: whose books, which period, which comparison,
 * which basis and the report-specific options. Built from validated input by ReportRequest::make().
 */
final class ReportRequest
{
    /** @param  array<string, mixed>  $options */
    public function __construct(
        public readonly LedgerContext $context,
        public readonly LedgerSetting $settings,
        public readonly ReportPeriod $period,
        public readonly ?ReportPeriod $comparison,
        public readonly ReportBasis $basis,
        public readonly array $options,
        public readonly string $compareMode,
        public readonly int $journalSeq,
        public readonly string $fingerprint,
        public readonly CarbonImmutable $generatedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $input  preset, from, to, compare, basis and the report's own options
     * @param  list<string>  $optionKeys  the option names this report accepts (anything else is dropped)
     */
    public static function make(LedgerContext $context, LedgerSetting $settings, array $input, array $optionKeys = [], ?CarbonImmutable $today = null): self
    {
        // A point-in-time report asks for one date ("as at"): the period then starts and ends on it.
        if (empty($input['preset']) || $input['preset'] === 'custom') {
            if (! empty($input['to']) && empty($input['from'])) {
                $input['from'] = $input['to'];
            } elseif (! empty($input['from']) && empty($input['to'])) {
                $input['to'] = $input['from'];
            }
        }

        $period = ReportPeriod::resolve($input, (int) $settings->fiscal_year_start_month, $today);
        $compareMode = in_array($input['compare'] ?? 'none', array_keys(ReportPeriod::COMPARISONS), true) ? (string) ($input['compare'] ?? 'none') : 'none';
        $basis = ReportBasis::tryFrom((string) ($input['basis'] ?? '')) ?? ReportBasis::Accrual;
        $options = [];
        foreach ($optionKeys as $key) {
            if (isset($input[$key]) && $input[$key] !== '') {
                $options[$key] = is_scalar($input[$key]) ? (string) $input[$key] : $input[$key];
            }
        }

        $cache = app(ReportCache::class);

        return new self($context, $settings, $period, $period->comparison($compareMode), $basis, $options, $compareMode, $cache->journalSequence($context), $cache->fingerprint($context), $today ?? CarbonImmutable::now());
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    public function currency(): string
    {
        return $this->settings->base_currency;
    }

    /** The normalised parameters: what a saved preset or an export stores and what the page rebuilds the form from. @return array<string, mixed> */
    public function params(): array
    {
        return array_filter([
            'preset' => $this->period->preset === 'custom' ? 'custom' : $this->period->preset,
            'from' => $this->period->from,
            'to' => $this->period->to,
            'compare' => $this->compareMode,
            'basis' => $this->basis->value,
        ] + $this->options, fn ($v) => $v !== null && $v !== '');
    }

    /** The parameters a saved preset keeps: a named period stays relative ("this month" means next month's month next month). @return array<string, mixed> */
    public function presetParams(): array
    {
        $params = $this->params();
        if ($this->period->preset !== 'custom') {
            unset($params['from'], $params['to']);
        }

        return $params;
    }

    /**
     * A key for caching one computation: the ledger, the journal fingerprint and exactly what was asked. Different
     * books or a new posting give a different key, so a cached figure is never served for books that have moved on.
     *
     * @param  array<string, mixed>  $parts
     */
    public function cacheKey(string $name, array $parts = []): string
    {
        return $this->context->key().'|'.$this->fingerprint.'|'.$name.'|'.md5(json_encode($parts));
    }
}
