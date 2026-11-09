<?php

namespace App\Services\Shared;

use App\Models\Shared\TaxCode;
use App\Services\Shared\Tax\DocumentTax;
use App\Services\Shared\Tax\LineTax;
use App\Services\Shared\Tax\TaxLineInput;
use App\Services\Shared\Tax\TaxMath;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Calculates tax on document lines, on the server, from rules held as data.
 *
 * For each line it resolves the tax code and the rate in force on the line's tax point (its own date,
 * else the document date) through TaxRuleBook, which refuses to answer when no version is valid. It
 * then does exact integer arithmetic (TaxMath) under the jurisdiction's rounding policy, line or
 * document, itself a versioned rule. It returns net, tax and gross in cents together with the code,
 * the rate and the rule versions used. Nothing here reads a browser-supplied total.
 *
 * The rounding policy is read from the rule `vat.rounding.level` by default. That rule is a Dot.Finance
 * design choice, not a SARS figure (seeded with legal status policy and confidence low, and said so); its
 * version is recorded with the answer but it does not lower the answer's confidence, which is the least
 * certain of the RATES used. Change $roundingRuleKey for another tax type or jurisdiction.
 */
final class TaxCalculator
{
    public function __construct(private readonly TaxRuleBook $book, private readonly string $roundingRuleKey = 'vat.rounding.level') {}

    /** One line on its own (a quote preview, a single-line receipt). */
    public function forLine(TaxLineInput $line, string $jurisdiction, CarbonInterface|string $on, bool $inclusive = false): LineTax
    {
        return $this->forDocument([$line], $jurisdiction, $on, $inclusive)->lines[0];
    }

    /**
     * @param  list<TaxLineInput>  $lines
     */
    public function forDocument(array $lines, string $jurisdiction, CarbonInterface|string $on, bool $inclusive = false): DocumentTax
    {
        if ($lines === []) {
            throw new InvalidArgumentException('A document needs at least one line.');
        }

        $documentDate = CarbonImmutable::parse($on instanceof CarbonInterface ? $on->toDateString() : substr($on, 0, 10));
        $roundingRule = $this->book->resolve($jurisdiction, $this->roundingRuleKey, $documentDate);
        $level = $roundingRule->text();

        if (! in_array($level, ['line', 'document'], true)) {
            throw new InvalidArgumentException("Rounding level must be line or document, the rule says {$level}.");
        }

        // Pass 1: amount, code and rate for every line.
        $prepared = [];
        foreach (array_values($lines) as $i => $line) {
            $point = $line->taxPoint ? CarbonImmutable::parse($line->taxPoint->toDateString()) : $documentDate;
            $code = $this->book->taxCode($jurisdiction, $line->taxCode, $point);
            [$rateBp, $rateVersion, $rateConfidence] = $this->rateFor($code, $jurisdiction, $point);

            $amounts = TaxMath::lineAmount($line->unitCents, $line->quantity, $line->discountBp, $line->discountCents);

            $prepared[$i] = [
                'input' => $line,
                'code' => $code,
                'rate' => $rateBp,
                'inclusive' => $line->inclusive ?? $inclusive,
                'amounts' => $amounts,
                'versions' => array_values(array_filter([$rateVersion, 'tax_codes#'.$code->id])),
                'confidence' => $rateConfidence,
            ];
        }

        // Pass 2: the tax of each line under the rounding policy.
        $taxes = $level === 'line' ? $this->lineLevel($prepared) : $this->documentLevel($prepared);

        $out = [];
        $documentVersions = [$roundingRule->version()];
        $confidences = [];

        foreach ($prepared as $i => $p) {
            $amount = $p['amounts']['amount'];
            $tax = $taxes[$i];

            [$net, $gross] = $p['inclusive'] ? [$amount - $tax, $amount] : [$amount, $amount + $tax];

            $versions = array_merge($p['versions'], [$roundingRule->version()]);
            $documentVersions = array_merge($documentVersions, $p['versions']);
            $confidences[] = $p['confidence'];

            $out[] = new LineTax(
                index: $i,
                reference: $p['input']->reference,
                jurisdiction: $jurisdiction,
                taxCode: $p['code']->code,
                taxKind: $p['code']->kind,
                rateBp: $p['rate'],
                inclusive: $p['inclusive'],
                beforeDiscountCents: $p['amounts']['before_discount'],
                discountCents: $p['amounts']['discount'],
                netCents: $net,
                taxCents: $tax,
                grossCents: $gross,
                roundingLevel: $level,
                boxOutput: $p['code']->box_output,
                boxInput: $p['code']->box_input,
                ruleVersions: array_values(array_unique($versions)),
                confidence: $p['confidence'],
            );
        }

        $summary = [];
        foreach ($out as $line) {
            $key = $line->taxCode.'@'.$line->rateBp;
            $summary[$key] ??= ['tax_code' => $line->taxCode, 'rate_bp' => $line->rateBp, 'net_cents' => 0, 'tax_cents' => 0, 'gross_cents' => 0];
            $summary[$key]['net_cents'] += $line->netCents;
            $summary[$key]['tax_cents'] += $line->taxCents;
            $summary[$key]['gross_cents'] += $line->grossCents;
        }

        return new DocumentTax(
            lines: $out,
            summary: array_values($summary),
            netCents: array_sum(array_map(fn (LineTax $l) => $l->netCents, $out)),
            taxCents: array_sum(array_map(fn (LineTax $l) => $l->taxCents, $out)),
            grossCents: array_sum(array_map(fn (LineTax $l) => $l->grossCents, $out)),
            roundingLevel: $level,
            ruleVersions: array_values(array_unique($documentVersions)),
            confidence: $this->weakest($confidences),
        );
    }

    /** @return array{0: int, 1: ?string, 2: string} rate in basis points, the rate version, its confidence */
    private function rateFor(TaxCode $code, string $jurisdiction, CarbonImmutable $point): array
    {
        if ($code->rate_ref === null) {
            if ($code->kind === 'standard') {
                throw new InvalidArgumentException("Tax code {$code->code} is a standard-rate code but names no rate.");
            }

            // zero-rated, exempt and out-of-scope supplies carry no tax by definition
            return [0, null, 'high'];
        }

        $rate = $this->book->rate($jurisdiction, $code->rate_ref, $point);

        return [$rate->rateBp, $rate->version(), $rate->confidence];
    }

    /** @param  array<int, array<string, mixed>>  $prepared @return array<int, int> */
    private function lineLevel(array $prepared): array
    {
        $taxes = [];
        foreach ($prepared as $i => $p) {
            $amount = $p['amounts']['amount'];
            $taxes[$i] = $p['inclusive'] ? TaxMath::inclusive($amount, $p['rate'])['tax'] : TaxMath::exclusive($amount, $p['rate'])['tax'];
        }

        return $taxes;
    }

    /** @param  array<int, array<string, mixed>>  $prepared @return array<int, int> */
    private function documentLevel(array $prepared): array
    {
        $groups = [];
        foreach ($prepared as $i => $p) {
            $groups[$p['code']->code.'@'.$p['rate'].'@'.($p['inclusive'] ? 'i' : 'x')][] = $i;
        }

        $taxes = [];
        foreach ($groups as $indexes) {
            $first = $prepared[$indexes[0]];
            $shares = TaxMath::documentGroupTax(
                array_map(fn (int $i) => $prepared[$i]['amounts']['amount'], $indexes),
                $first['rate'],
                $first['inclusive'],
            );

            foreach ($indexes as $n => $i) {
                $taxes[$i] = $shares[$n];
            }
        }

        ksort($taxes);

        return $taxes;
    }

    /** @param  list<string>  $confidences */
    private function weakest(array $confidences): string
    {
        foreach (['low', 'medium', 'high'] as $level) {
            if (in_array($level, $confidences, true)) {
                return $level;
            }
        }

        return 'high';
    }
}
