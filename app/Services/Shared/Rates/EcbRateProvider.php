<?php

namespace App\Services\Shared\Rates;

use App\Services\Shared\FxMath;
use App\Support\Shared\Exceptions\RateProviderDisabled;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The European Central Bank's euro foreign exchange reference rates (a free, published XML feed,
 * https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml). Verified on 2026-11-09 by fetching it:
 * HTTP 200, one dated <Cube time="..."> holding <Cube currency="ZAR" rate="18.4649"/> style entries,
 * every rate quoted as units of the currency per 1 EUR.
 *
 * Limits, stated plainly: base EUR only; the daily file carries the latest working day, not history;
 * the ECB publishes reference rates, not rates you can trade at. OFF unless FINANCE_FX_ECB_ENABLED is
 * true; nothing in the app calls it on a schedule. Rates are parsed from text to integers, never floats.
 */
final class EcbRateProvider implements RateProvider
{
    private const NAMESPACE = 'http://www.ecb.int/vocabulary/2002-08-01/eurofxref';

    public function name(): string
    {
        return 'ecb';
    }

    public function fetch(string $base, array $quotes, ?CarbonInterface $on = null): array
    {
        if (! config('shared.fx.ecb_enabled')) {
            throw new RateProviderDisabled('The ECB rate feed is switched off. Set FINANCE_FX_ECB_ENABLED=true to allow it, or enter rates by hand.');
        }

        if (strtoupper($base) !== 'EUR') {
            throw new RateProviderDisabled('The ECB feed quotes rates against the euro only.');
        }

        try {
            $response = Http::timeout((int) config('shared.fx.timeout_seconds'))->accept('text/xml')->get((string) config('shared.fx.ecb_url'));
        } catch (Throwable $e) {
            throw new RateProviderDisabled('The ECB feed could not be reached: '.$e->getMessage());
        }

        if (! $response->successful()) {
            throw new RateProviderDisabled('The ECB feed answered with HTTP '.$response->status().'.');
        }

        return $this->parse($response->body(), $quotes);
    }

    /**
     * @param  list<string>  $quotes
     * @return array<string, array{rate_e8: int, as_of: string}>
     */
    public function parse(string $xml, array $quotes): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml, options: LIBXML_NONET);
        libxml_use_internal_errors($previous);

        if ($document === false) {
            throw new RateProviderDisabled('The ECB feed was not valid XML.');
        }

        $document->registerXPathNamespace('e', self::NAMESPACE);
        $days = $document->xpath('//e:Cube[@time]') ?: [];

        if ($days === []) {
            throw new RateProviderDisabled('The ECB feed carried no dated rates.');
        }

        $asOf = (string) $days[0]['time'];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) !== 1) {
            throw new RateProviderDisabled('The ECB feed carried an unreadable date.');
        }

        $wanted = array_flip(array_map('strtoupper', $quotes));
        $rates = [];

        $days[0]->registerXPathNamespace('e', self::NAMESPACE);

        foreach ($days[0]->xpath('e:Cube[@currency]') ?: [] as $cube) {
            $currency = strtoupper((string) $cube['currency']);
            if (! isset($wanted[$currency])) {
                continue;
            }

            $rates[$currency] = ['rate_e8' => FxMath::parseRate((string) $cube['rate']), 'as_of' => $asOf];
        }

        return $rates;
    }
}
