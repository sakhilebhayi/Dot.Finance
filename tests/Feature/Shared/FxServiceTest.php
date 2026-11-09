<?php

namespace Tests\Feature\Shared;

use App\Models\Shared\FxRate;
use App\Services\Shared\FxMath;
use App\Services\Shared\FxService;
use App\Services\Shared\Rates\EcbRateProvider;
use App\Services\Shared\Rates\ManualRateProvider;
use App\Support\Ledger\LedgerContext;
use App\Support\Shared\Exceptions\NoFxRate;
use App\Support\Shared\Exceptions\RateProviderDisabled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use LogicException;
use Tests\Concerns\BuildsCompanyLedgers;
use Tests\TestCase;

class FxServiceTest extends TestCase
{
    use BuildsCompanyLedgers;
    use RefreshDatabase;

    private function fx(): FxService
    {
        return app(FxService::class);
    }

    private function global(string $from, string $to, string $rate, string $asOf, string $source = 'manual'): FxRate
    {
        return $this->fx()->forContext(null)->record($from, $to, FxMath::parseRate($rate), $asOf, $source, global: true);
    }

    // ── looking a rate up ─────────────────────────────────────────────

    public function test_the_rate_for_the_day_is_used(): void
    {
        $this->global('USD', 'ZAR', '18.4649', '2026-11-06');

        $quote = $this->fx()->forContext(null)->rate('USD', 'ZAR', '2026-11-06');

        $this->assertSame(1_846_490_000, $quote->rateE8);
        $this->assertSame('18.4649', $quote->rateString());
        $this->assertFalse($quote->isFallback());
        $this->assertSame(0, $quote->ageDays());
        $this->assertSame('manual', $quote->source);
        $this->assertFalse($quote->inverted);
    }

    public function test_a_day_without_a_rate_falls_back_to_the_nearest_prior_one(): void
    {
        $this->global('USD', 'ZAR', '18.00', '2026-11-02');
        $this->global('USD', 'ZAR', '18.50', '2026-11-06');
        $book = $this->fx()->forContext(null);

        $weekend = $book->rate('USD', 'ZAR', '2026-11-08');

        $this->assertSame(1_850_000_000, $weekend->rateE8);
        $this->assertTrue($weekend->isFallback());
        $this->assertSame(2, $weekend->ageDays());
        $this->assertSame('2026-11-06', $weekend->asOf->toDateString());
        $this->assertSame('2026-11-08', $weekend->requestedOn->toDateString());

        $this->assertSame(1_800_000_000, $book->rate('USD', 'ZAR', '2026-11-05')->rateE8, 'between the two: the earlier one');
    }

    public function test_a_rate_from_the_future_is_never_used(): void
    {
        $this->global('USD', 'ZAR', '18.50', '2026-11-06');

        $this->expectException(NoFxRate::class);
        $this->expectExceptionMessage('on or before 2026-11-05');
        $this->fx()->forContext(null)->rate('USD', 'ZAR', '2026-11-05');
    }

    public function test_a_pair_with_no_rate_at_all_is_refused(): void
    {
        $this->global('USD', 'ZAR', '18.50', '2026-11-06');

        $this->expectException(NoFxRate::class);
        $this->fx()->forContext(null)->rate('EUR', 'ZAR', '2026-11-06');
    }

    public function test_a_rate_older_than_the_allowed_age_is_refused(): void
    {
        $this->global('USD', 'ZAR', '18.50', '2026-11-01');
        $book = $this->fx()->forContext(null);

        $this->assertSame(1_850_000_000, $book->rate('USD', 'ZAR', '2026-11-08', maxAgeDays: 7)->rateE8);

        $this->expectException(NoFxRate::class);
        $this->expectExceptionMessage('older than the 5 days allowed');
        $book->rate('USD', 'ZAR', '2026-11-08', maxAgeDays: 5)->rateE8;
    }

    public function test_the_same_currency_needs_no_rate(): void
    {
        $quote = $this->fx()->forContext(null)->rate('ZAR', 'zar', '2026-11-06');

        $this->assertSame(100_000_000, $quote->rateE8);
        $this->assertSame('identity', $quote->source);
    }

    public function test_the_opposite_direction_is_inverted(): void
    {
        $this->global('USD', 'ZAR', '20', '2026-11-06');

        $quote = $this->fx()->forContext(null)->rate('ZAR', 'USD', '2026-11-06');

        $this->assertSame(5_000_000, $quote->rateE8, '1 rand is 0.05 dollars');
        $this->assertTrue($quote->inverted);
    }

    public function test_a_newer_inverse_beats_an_older_direct_rate(): void
    {
        $this->global('USD', 'ZAR', '18.00', '2026-11-02');
        $this->global('ZAR', 'USD', '0.05', '2026-11-06');

        $quote = $this->fx()->forContext(null)->rate('USD', 'ZAR', '2026-11-06');

        $this->assertSame(2_000_000_000, $quote->rateE8);
        $this->assertTrue($quote->inverted);
        $this->assertSame('2026-11-06', $quote->asOf->toDateString());
    }

    // ── history is never overwritten ──────────────────────────────────

    public function test_a_correction_is_a_new_row_and_the_lookup_takes_the_newest_for_the_day(): void
    {
        $first = $this->global('USD', 'ZAR', '18.00', '2026-11-06');
        $correction = $this->global('USD', 'ZAR', '18.10', '2026-11-06');

        $this->assertSame(2, FxRate::globalOnly()->count(), 'both are kept');
        $this->assertSame(1_810_000_000, $this->fx()->forContext(null)->rate('USD', 'ZAR', '2026-11-06')->rateE8);
        $this->assertSame($correction->id, $this->fx()->forContext(null)->rate('USD', 'ZAR', '2026-11-06')->rowId);
        $this->assertSame(1_800_000_000, $first->fresh()->rate_e8);
    }

    public function test_a_rate_cannot_be_edited_or_deleted(): void
    {
        $rate = $this->global('USD', 'ZAR', '18.00', '2026-11-06');

        $this->assertThrows(fn () => $rate->update(['rate_e8' => 1]), LogicException::class);
        $this->assertThrows(fn () => $rate->fresh()->delete(), LogicException::class);
        $this->assertThrows(fn () => FxRate::query()->whereKey($rate->id)->first()->forceFill(['note' => 'x'])->save(), LogicException::class);
        $this->assertSame(1_800_000_000, $rate->fresh()->rate_e8);
    }

    public function test_a_rate_records_when_it_was_stored_and_by_whom(): void
    {
        $user = $this->user();
        $rate = $this->fx()->forContext(LedgerContext::personal($user))->record('USD', 'ZAR', 1_850_000_000, '2026-11-06', 'manual', $user, 'month-end rate');

        $this->assertNotNull($rate->fetched_at);
        $this->assertSame($user->id, $rate->created_by);
        $this->assertSame('month-end rate', $rate->note);
        $this->assertSame($user->id, $rate->user_id);
    }

    public function test_bad_rates_are_refused(): void
    {
        $user = $this->user();
        $book = $this->fx()->forContext(LedgerContext::personal($user));

        foreach ([
            fn () => $book->record('USD', 'USD', 1, '2026-11-06'),
            fn () => $book->record('USD', 'ZAR', 0, '2026-11-06'),
            fn () => $book->record('USD', 'ZAR', -5, '2026-11-06'),
            fn () => $book->record('USD', 'XYZ', 5, '2026-11-06'),
            fn () => $this->fx()->forContext(null)->record('USD', 'ZAR', 5, '2026-11-06'),   // no ledger and not marked global
        ] as $attempt) {
            $this->assertThrows($attempt, InvalidArgumentException::class);
        }

        $this->assertSame(0, FxRate::ownedBy(LedgerContext::personal($user))->count());
    }

    // ── whose rate it is ──────────────────────────────────────────────

    public function test_a_ledgers_own_rate_beats_a_global_rate_of_the_same_day_for_that_ledger_only(): void
    {
        $alice = $this->user();
        $bob = $this->user();
        $this->global('USD', 'ZAR', '18.00', '2026-11-06');
        $this->fx()->forContext(LedgerContext::personal($alice))->record('USD', 'ZAR', 1_900_000_000, '2026-11-06');

        $this->assertSame(1_900_000_000, $this->fx()->forContext(LedgerContext::personal($alice))->rate('USD', 'ZAR', '2026-11-06')->rateE8);
        $this->assertTrue($this->fx()->forContext(LedgerContext::personal($alice))->rate('USD', 'ZAR', '2026-11-06')->isLedgerRate);
        $this->assertSame(1_800_000_000, $this->fx()->forContext(LedgerContext::personal($bob))->rate('USD', 'ZAR', '2026-11-06')->rateE8, "Alice's own rate is invisible to Bob");
    }

    public function test_a_newer_global_rate_beats_an_older_ledger_rate(): void
    {
        $alice = $this->user();
        $this->fx()->forContext(LedgerContext::personal($alice))->record('USD', 'ZAR', 1_900_000_000, '2026-11-02');
        $this->global('USD', 'ZAR', '18.00', '2026-11-06');

        $this->assertSame(1_800_000_000, $this->fx()->forContext(LedgerContext::personal($alice))->rate('USD', 'ZAR', '2026-11-06')->rateE8, 'the most recent day wins; the ledger only wins ties');
    }

    public function test_the_model_scope_hides_other_ledgers_rates(): void
    {
        $alice = $this->user();
        $bob = $this->user();
        $this->fx()->forContext(LedgerContext::personal($alice))->record('USD', 'ZAR', 1_900_000_000, '2026-11-06');
        $this->global('EUR', 'ZAR', '20', '2026-11-06');

        $this->actingAs($bob->fresh());
        $this->assertSame(['EUR'], FxRate::query()->pluck('from_currency')->all());

        $this->actingAs($alice->fresh());
        $this->assertEqualsCanonicalizing(['EUR', 'USD'], FxRate::query()->pluck('from_currency')->all());
    }

    public function test_the_ambient_ledger_is_used_when_none_is_named(): void
    {
        $owner = $this->user();
        $company = $this->company($owner);
        $this->global('USD', 'ZAR', '18.00', '2026-11-06');
        $this->fx()->forContext(LedgerContext::company($company))->record('USD', 'ZAR', 1_700_000_000, '2026-11-06');

        $this->inCompany($owner, $company);
        $this->assertSame(1_700_000_000, app(FxService::class)->rate('USD', 'ZAR', '2026-11-06')->rateE8);

        $this->inPersonal($owner);
        $this->assertSame(1_800_000_000, app(FxService::class)->rate('USD', 'ZAR', '2026-11-06')->rateE8);
    }

    // ── converting ────────────────────────────────────────────────────

    public function test_convert_uses_the_rate_and_each_currencys_digits(): void
    {
        $this->global('USD', 'ZAR', '18.4649', '2026-11-06');
        $this->global('JPY', 'ZAR', '0.103', '2026-11-06');
        $this->global('KWD', 'ZAR', '60', '2026-11-06');
        $book = $this->fx()->forContext(null);

        $usd = $book->convert(10_000, 'USD', 'ZAR', '2026-11-06');
        $this->assertSame([184_649, 'ZAR'], [$usd->amountMinor, $usd->currency]);
        $this->assertSame(1_846_490_000, $usd->quote->rateE8);

        $this->assertSame(10_300, $book->convert(1_000, 'JPY', 'ZAR', '2026-11-06')->amountMinor, '1000 yen has no minor unit');
        $this->assertSame(6_000, $book->convert(1_000, 'KWD', 'ZAR', '2026-11-06')->amountMinor, '1.000 dinar has three');
        $this->assertSame(1_000, $book->convert(10_300, 'ZAR', 'JPY', '2026-11-06')->amountMinor, 'back through the inverted rate');
        $this->assertSame(500, $book->convert(500, 'ZAR', 'ZAR', '2026-11-06')->amountMinor);
    }

    public function test_converting_there_and_back_is_within_a_cent_or_two(): void
    {
        $this->global('USD', 'ZAR', '18.4649', '2026-11-06');
        $book = $this->fx()->forContext(null);

        $there = $book->convert(123_456, 'USD', 'ZAR', '2026-11-06')->amountMinor;
        $back = $book->convert($there, 'ZAR', 'USD', '2026-11-06')->amountMinor;

        $this->assertLessThanOrEqual(1, abs($back - 123_456));
    }

    // ── providers ─────────────────────────────────────────────────────

    public function test_a_manual_provider_hands_over_what_a_person_entered(): void
    {
        $provider = new ManualRateProvider('USD', ['ZAR' => ['rate_e8' => 1_850_000_000, 'as_of' => '2026-11-06'], 'EUR' => ['rate_e8' => 92_000_000, 'as_of' => '2026-11-06']]);

        $stored = $this->fx()->forContext(null)->importFrom($provider, 'USD', ['ZAR', 'xyz']);

        $this->assertCount(1, $stored, 'unknown and unrequested currencies are left out');
        $this->assertSame(['USD', 'ZAR', 'manual'], [$stored[0]->from_currency, $stored[0]->to_currency, $stored[0]->source]);
        $this->assertTrue($stored[0]->isGlobal());
        $this->assertSame([], $provider->fetch('GBP', ['ZAR']), 'a different base has nothing');
    }

    public function test_the_ecb_feed_is_off_by_default_and_makes_no_request(): void
    {
        Http::fake();

        $this->assertFalse(config('shared.fx.ecb_enabled'));
        $this->assertThrows(fn () => (new EcbRateProvider)->fetch('EUR', ['ZAR']), RateProviderDisabled::class);
        $this->assertThrows(fn () => $this->fx()->forContext(null)->importFrom(new EcbRateProvider, 'EUR', ['ZAR']), RateProviderDisabled::class);

        Http::assertNothingSent();
        $this->assertSame(0, FxRate::query()->count());
    }

    public function test_the_ecb_feed_is_parsed_exactly_when_switched_on(): void
    {
        config(['shared.fx.ecb_enabled' => true]);
        Http::fake(['www.ecb.europa.eu/*' => Http::response(file_get_contents(base_path('tests/Fixtures/ecb-eurofxref-daily.xml')), 200, ['Content-Type' => 'text/xml'])]);

        $stored = $this->fx()->forContext(null)->importFrom(new EcbRateProvider, 'EUR', ['ZAR', 'USD', 'JPY', 'ISK']);

        Http::assertSentCount(1);
        $byQuote = collect($stored)->keyBy('to_currency');

        $this->assertEqualsCanonicalizing(['ZAR', 'USD', 'JPY'], $byQuote->keys()->all(), 'ISK is not in our currency list and is skipped, not an error');
        $this->assertSame(1_846_490_000, $byQuote['ZAR']->rate_e8, '18.4649 as text, never a float');
        $this->assertSame(111_650_000, $byQuote['USD']->rate_e8);
        $this->assertSame(17_677_000_000, $byQuote['JPY']->rate_e8);
        $this->assertSame('2026-11-06', $byQuote['ZAR']->as_of->toDateString());
        $this->assertSame('ecb', $byQuote['ZAR']->source);
        $this->assertTrue($byQuote['ZAR']->isGlobal());

        // and the rate is usable: EUR 100.00 on a Sunday uses Friday's rate
        $converted = $this->fx()->forContext(null)->convert(10_000, 'EUR', 'ZAR', '2026-11-08');
        $this->assertSame(184_649, $converted->amountMinor);
        $this->assertTrue($converted->quote->isFallback());
    }

    public function test_the_ecb_feed_rejects_other_bases_bad_status_and_bad_xml(): void
    {
        config(['shared.fx.ecb_enabled' => true]);
        $provider = new EcbRateProvider;

        $this->assertThrows(fn () => $provider->fetch('USD', ['ZAR']), RateProviderDisabled::class);

        Http::fake(['*' => Http::response('server error', 500)]);
        $this->assertThrows(fn () => $provider->fetch('EUR', ['ZAR']), RateProviderDisabled::class);

        Http::fake(['*' => Http::response('<not xml', 200)]);
        $this->assertThrows(fn () => $provider->fetch('EUR', ['ZAR']), RateProviderDisabled::class);

        Http::fake(['*' => Http::response('<?xml version="1.0"?><Envelope/>', 200)]);
        $this->assertThrows(fn () => $provider->fetch('EUR', ['ZAR']), RateProviderDisabled::class);

        $this->assertSame(0, FxRate::query()->count());
    }

    public function test_a_rate_with_too_many_decimals_in_a_feed_is_refused_not_rounded(): void
    {
        $xml = '<?xml version="1.0"?><gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref"><Cube><Cube time="2026-11-06"><Cube currency="ZAR" rate="18.123456789"/></Cube></Cube></gesmes:Envelope>';

        $this->assertThrows(fn () => (new EcbRateProvider)->parse($xml, ['ZAR']), InvalidArgumentException::class);
    }
}
