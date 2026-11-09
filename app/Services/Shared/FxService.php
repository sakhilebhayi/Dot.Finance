<?php

namespace App\Services\Shared;

use App\Models\Shared\Currency;
use App\Models\Shared\FxRate;
use App\Models\User;
use App\Services\Shared\Rates\RateProvider;
use App\Support\Ledger\LedgerContext;
use App\Support\Shared\Exceptions\NoFxRate;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Stores exchange rates as history and converts with them.
 *
 *  - A rate is stored as an integer (rate_e8) with the day it applies to (as_of) and the moment it was
 *    recorded (fetched_at). Rows are never overwritten: a correction is a new row and the lookup takes the
 *    newest entry for the day it finds.
 *  - rate() looks up the rate as of a date: the entry for that day, or else the nearest PRIOR day (never a
 *    later one: a rate from the future was not known then). Entries from the opposite direction are inverted.
 *    A ledger's own entry beats a global one of the same day.
 *  - convert() works in minor units and honours each currency's digits (JPY 0, ZAR 2, KWD 3).
 *  - Gain/loss helpers are pure functions in FxMath.
 *
 * Nothing here talks to the network. importFrom() stores what a RateProvider hands it.
 */
final class FxService
{
    private ?LedgerContext $context = null;

    private bool $contextFixed = false;

    public function forContext(?LedgerContext $context): static
    {
        $copy = clone $this;
        $copy->context = $context;
        $copy->contextFixed = true;

        return $copy;
    }

    /**
     * Record a rate. $global marks a published reference rate (only providers and console use it); otherwise
     * the rate belongs to the active ledger.
     */
    public function record(string $from, string $to, int $rateE8, CarbonInterface|string $asOf, string $source = 'manual', ?User $by = null, ?string $note = null, bool $global = false, ?LedgerContext $context = null): FxRate
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            throw new InvalidArgumentException('A rate converts between two different currencies.');
        }
        if ($rateE8 <= 0) {
            throw new InvalidArgumentException('An exchange rate must be greater than zero.');
        }

        Currency::minorUnits($from);
        Currency::minorUnits($to);

        $owner = ['user_id' => null, 'team_id' => null];
        if (! $global) {
            $context ??= $this->activeContext() ?? throw new InvalidArgumentException('No ledger is active, so a ledger rate cannot be recorded.');
            $owner = $context->ownerAttributes();
        }

        return FxRate::query()->create($owner + [
            'from_currency' => $from,
            'to_currency' => $to,
            'rate_e8' => $rateE8,
            'as_of' => $this->day($asOf)->toDateString(),
            'source' => $source,
            'fetched_at' => now(),
            'created_by' => $by?->id,
            'note' => $note,
        ]);
    }

    /**
     * Store the rates a provider returns, as global reference rates (history, one new row per rate).
     *
     * @param  list<string>  $quotes
     * @return list<FxRate>
     */
    public function importFrom(RateProvider $provider, string $base, array $quotes, ?CarbonInterface $on = null): array
    {
        $stored = [];

        // only currencies the app knows: a provider that offers more must not fail the whole import
        $quotes = array_values(array_intersect(array_map('strtoupper', $quotes), array_keys(Currency::digitsByCode())));

        DB::transaction(function () use ($provider, $base, $quotes, $on, &$stored) {
            foreach ($provider->fetch($base, $quotes, $on) as $quote => $entry) {
                $stored[] = $this->record($base, $quote, $entry['rate_e8'], $entry['as_of'], $provider->name(), global: true);
            }
        });

        return $stored;
    }

    /**
     * The rate for 1 unit of $from in $to on $on.
     *
     * @param  ?int  $maxAgeDays  refuse a rate older than this many days (null = any age)
     *
     * @throws NoFxRate
     */
    public function rate(string $from, string $to, CarbonInterface|string $on, ?int $maxAgeDays = null): FxQuote
    {
        $from = strtoupper($from);
        $to = strtoupper($to);
        $date = $this->day($on);

        if ($from === $to) {
            return new FxQuote($from, $to, FxMath::SCALE, $date, $date, 'identity', null, false, false);
        }

        $direct = $this->best($from, $to, $date);
        $inverse = $this->best($to, $from, $date);

        $pick = match (true) {
            $direct !== null && $inverse !== null => $inverse->as_of->toDateString() > $direct->as_of->toDateString() ? [$inverse, true] : [$direct, false],
            $direct !== null => [$direct, false],
            $inverse !== null => [$inverse, true],
            default => null,
        };

        if ($pick === null) {
            throw new NoFxRate("No {$from} to {$to} rate is on record on or before {$date->toDateString()}. Enter a rate for that currency pair first.");
        }

        [$row, $inverted] = $pick;
        $asOf = CarbonImmutable::parse($row->as_of->toDateString());
        $age = (int) $asOf->diffInDays($date);

        if ($maxAgeDays !== null && $age > $maxAgeDays) {
            throw new NoFxRate("The latest {$from} to {$to} rate is from {$asOf->toDateString()}, {$age} days before {$date->toDateString()}, which is older than the {$maxAgeDays} days allowed.");
        }

        return new FxQuote($from, $to, $inverted ? FxMath::invert($row->rate_e8) : $row->rate_e8, $asOf, $date, $row->source, (int) $row->id, $inverted, ! $row->isGlobal());
    }

    /** Convert minor units of $from into minor units of $to using the rate in force on $on. */
    public function convert(int $amountMinor, string $from, string $to, CarbonInterface|string $on, ?int $maxAgeDays = null): FxConversion
    {
        $quote = $this->rate($from, $to, $on, $maxAgeDays);

        return new FxConversion(
            FxMath::convert($amountMinor, $quote->rateE8, Currency::minorUnits($from), Currency::minorUnits($to)),
            strtoupper($to),
            $quote,
        );
    }

    /** The newest stored entry for the pair on the latest day on or before $date. */
    private function best(string $from, string $to, CarbonImmutable $date): ?FxRate
    {
        return FxRate::visibleTo($this->activeContext())
            ->where('from_currency', $from)->where('to_currency', $to)
            ->where('as_of', '<=', $date->toDateString())
            ->orderByDesc('as_of')
            // a ledger's own rate beats a global one of the same day; then the most recently recorded
            ->orderByRaw('CASE WHEN user_id IS NULL AND team_id IS NULL THEN 0 ELSE 1 END DESC')
            ->orderByDesc('id')
            ->first();
    }

    private function activeContext(): ?LedgerContext
    {
        return $this->contextFixed ? $this->context : LedgerContext::current();
    }

    private function day(CarbonInterface|string $on): CarbonImmutable
    {
        return CarbonImmutable::parse($on instanceof CarbonInterface ? $on->toDateString() : substr($on, 0, 10))->startOfDay();
    }
}
