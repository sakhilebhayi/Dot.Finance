<?php

namespace App\Services\Shared;

use App\Models\Shared\TaxCode;
use App\Models\Shared\TaxRate;
use App\Models\Shared\TaxRule;
use App\Services\Shared\Tax\ResolvedRate;
use App\Services\Shared\Tax\ResolvedRule;
use App\Support\Ledger\LedgerContext;
use App\Support\Shared\Exceptions\NoValidTaxRule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The loader of tax rules as data. Ask it for a rule (jurisdiction, key, date) and it answers with
 * the one version that was in force that day, or it refuses with NoValidTaxRule. It never falls
 * back to "the current value", a nearby date, or a version whose legal status is only proposed.
 *
 * Which version wins when more than one covers the day: a ledger's own override beats a global
 * row; otherwise the version that started latest. The ledger is the active one unless one is
 * named with forContext(); with no ledger at all only global rows are seen.
 */
final class TaxRuleBook
{
    private ?LedgerContext $context = null;

    private bool $contextFixed = false;

    /** A copy of the book that answers for $context (null = global rules only) whatever the request is doing. */
    public function forContext(?LedgerContext $context): static
    {
        $copy = clone $this;
        $copy->context = $context;
        $copy->contextFixed = true;

        return $copy;
    }

    public function resolve(string $jurisdiction, string $key, CarbonInterface|string $on, string $qualifier = ''): ResolvedRule
    {
        $date = $this->day($on);

        $candidates = $this->rules($jurisdiction, $key, $qualifier)->get();

        $usable = $candidates->filter(fn (TaxRule $r) => $r->isUsable() && $r->coversDate($date));

        $winner = $usable
            ->sortByDesc(fn (TaxRule $r) => [$r->isGlobal() ? 0 : 1, $r->valid_from->toDateString(), $r->id])
            ->first();

        if ($winner instanceof TaxRule) {
            return ResolvedRule::from($winner);
        }

        throw new NoValidTaxRule($this->refusal('rule', "{$jurisdiction} {$key}".($qualifier !== '' ? " [{$qualifier}]" : ''), $date, $candidates->all()));
    }

    /** resolve(), or null when there is no valid version (for "is this rule defined here" questions). */
    public function find(string $jurisdiction, string $key, CarbonInterface|string $on, string $qualifier = ''): ?ResolvedRule
    {
        try {
            return $this->resolve($jurisdiction, $key, $on, $qualifier);
        } catch (NoValidTaxRule) {
            return null;
        }
    }

    /** The versions on record for a rule, newest first, whether or not they are usable (history for a screen). */
    public function history(string $jurisdiction, string $key, string $qualifier = ''): array
    {
        return $this->rules($jurisdiction, $key, $qualifier)->get()
            ->sortByDesc(fn (TaxRule $r) => $r->valid_from->toDateString())
            ->map(fn (TaxRule $r) => ResolvedRule::from($r))
            ->values()
            ->all();
    }

    public function rate(string $jurisdiction, string $rateKey, CarbonInterface|string $on): ResolvedRate
    {
        $date = $this->day($on);

        $candidates = TaxRate::visibleTo($this->activeContext())
            ->where('jurisdiction', $jurisdiction)->where('rate_key', $rateKey)->get();

        $winner = $candidates
            ->filter(fn (TaxRate $r) => in_array($r->legal_status, TaxRule::USABLE_STATUSES, true) && $r->coversDate($date))
            ->sortByDesc(fn (TaxRate $r) => [$r->isGlobal() ? 0 : 1, $r->valid_from->toDateString(), $r->id])
            ->first();

        if ($winner instanceof TaxRate) {
            return ResolvedRate::from($winner);
        }

        throw new NoValidTaxRule($this->refusal('rate', "{$jurisdiction} {$rateKey}", $date, $candidates->all()));
    }

    /** The tax code with this code that is in force on the date (an override beats a global one). */
    public function taxCode(string $jurisdiction, string $code, CarbonInterface|string $on): TaxCode
    {
        $date = $this->day($on);

        $candidates = TaxCode::visibleTo($this->activeContext())
            ->where('jurisdiction', $jurisdiction)->where('code', $code)->get();

        $winner = $candidates
            ->filter(fn (TaxCode $c) => $c->coversDate($date))
            ->sortByDesc(fn (TaxCode $c) => [$c->isGlobal() ? 0 : 1, $c->valid_from->toDateString(), $c->id])
            ->first();

        if ($winner instanceof TaxCode) {
            return $winner;
        }

        throw new NoValidTaxRule($this->refusal('tax code', "{$jurisdiction} {$code}", $date, $candidates->all()));
    }

    /** Tax codes in force on a date for a jurisdiction, in display order (for pickers). @return \Illuminate\Support\Collection<int, TaxCode> */
    public function taxCodesOn(string $jurisdiction, CarbonInterface|string $on, ?string $appliesTo = null)
    {
        $date = $this->day($on);

        return TaxCode::visibleTo($this->activeContext())
            ->where('jurisdiction', $jurisdiction)->where('is_active', true)
            ->when($appliesTo, fn (Builder $q) => $q->whereIn('applies_to', [$appliesTo, 'both']))
            ->get()
            ->filter(fn (TaxCode $c) => $c->coversDate($date))
            // an override replaces the global code of the same name
            ->sortByDesc(fn (TaxCode $c) => $c->isGlobal() ? 0 : 1)
            ->unique('code')
            ->sortBy(['sort_order', 'code'])
            ->values();
    }

    private function rules(string $jurisdiction, string $key, string $qualifier)
    {
        return TaxRule::visibleTo($this->activeContext())
            ->where('jurisdiction', $jurisdiction)->where('rule_key', $key)->where('qualifier', $qualifier);
    }

    private function activeContext(): ?LedgerContext
    {
        return $this->contextFixed ? $this->context : LedgerContext::current();
    }

    private function day(CarbonInterface|string $on): CarbonImmutable
    {
        return CarbonImmutable::parse($on instanceof CarbonInterface ? $on->toDateString() : substr($on, 0, 10))->startOfDay();
    }

    /** @param  list<TaxRule|TaxRate|TaxCode>  $candidates */
    private function refusal(string $what, string $name, CarbonImmutable $date, array $candidates): string
    {
        $on = $date->toDateString();

        if ($candidates === []) {
            return "No {$what} {$name} is on record, so nothing can be calculated for {$on}.";
        }

        $unusable = array_filter($candidates, fn ($c) => isset($c->legal_status) && ! in_array($c->legal_status, TaxRule::USABLE_STATUSES, true) && $c->coversDate($date));
        if ($unusable !== []) {
            return "The only {$what} {$name} covering {$on} has legal status ".reset($unusable)->legal_status.' (not yet law), so it is not used.';
        }

        $starts = collect($candidates)->map(fn ($c) => $c->valid_from->toDateString())->sort()->values();
        $ends = collect($candidates)->map(fn ($c) => $c->valid_to?->toDateString())->filter()->sort()->values();

        return "No version of {$what} {$name} is valid on {$on} (versions on record start from {$starts->first()}".($ends->isNotEmpty() ? ", the latest closed one ends {$ends->last()}" : '').'). Add a version that covers this date; nothing was assumed.';
    }
}
