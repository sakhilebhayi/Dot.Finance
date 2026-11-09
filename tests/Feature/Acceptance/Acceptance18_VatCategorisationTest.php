<?php

namespace Tests\Feature\Acceptance;

use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalLine;
use App\Models\Tax\TaxReviewResolution;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\PostingLine;
use App\Services\Accounting\PostingRequest;
use App\Services\Shared\TaxRuleBook;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Owner criterion 18: applicable VAT transactions are categorised correctly. Every VAT-bearing document line of the month carries
 * the tax code its nature calls for (standard, zero-rated, exempt, blocked entertainment), the VAT accounts agree with the codes,
 * and an entry that was categorised wrongly is caught by the review queue, which a person must resolve with a note on the record.
 */
class Acceptance18_VatCategorisationTest extends AcceptanceCase
{
    private function codeId(string $code, string $on = '2026-11-15'): int
    {
        return app(TaxRuleBook::class)->forContext(null)->taxCode('ZA', $code, $on)->id;
    }

    /** Net per tax code on income and expense lines of the month, in cents. @return array<string, int> */
    private function netByCode(): array
    {
        return DB::table('journal_lines as l')->join('tax_codes as c', 'c.id', '=', 'l.tax_code_id')->join('gl_accounts as g', 'g.id', '=', 'l.gl_account_id')
            ->where('l.team_id', $this->company->id)->whereIn('g.class', ['income', 'expense'])
            ->groupBy('c.code')->selectRaw('c.code as code, SUM(l.credit_cents) - SUM(l.debit_cents) as net')->pluck('net', 'code')->map(fn ($v) => (int) $v)->all();
    }

    private function postEntry(string $type, string $id, string $date, string $memo, array $lines): JournalEntry
    {
        return LedgerContext::run($this->ledger, fn () => app(JournalPoster::class)->post(new PostingRequest(CarbonImmutable::parse($date), $type, $id, 1, $memo, $lines)));
    }

    public function test_every_line_of_the_month_carries_the_tax_code_its_nature_calls_for_and_the_vat_follows_the_rate(): void
    {
        $this->runMonth(false);
        $net = $this->netByCode();

        // income (credit positive): standard 10,000.00 + 20,000.00 less the 2,000.00 credit note, zero-rated 2,000.00, exempt 500.00
        $this->assertSame(2_800_000, $net['ZA_VAT_STD'] + 660_000, 'standard-rated supplies net of the standard-rated purchases below');
        $this->assertSame(200_000, $net['ZA_VAT_ZERO']);
        $this->assertSame(50_000, $net['ZA_VAT_EXEMPT']);
        // expenses (debit positive, so negative here): purchases 4,000.00 - 400.00 + 2,000.00 + 1,000.00 claim; the entertainment bill is its own blocked code
        $this->assertSame(-103_500, $net['ZA_VAT_DENIED_ENTERTAINMENT'], 'entertainment: the 135.00 of VAT is cost, so 1,035.00 in all');

        // the VAT follows the code: 15 percent of the standard-rated base on each side, nothing on zero, exempt or blocked
        $this->assertSame(420_000, -$this->balance('vat_output'));
        $this->assertSame(99_000, $this->balance('vat_input'));
        $this->assertSame((int) round(0.15 * 2_800_000), -$this->balance('vat_output'));
        $this->assertSame((int) round(0.15 * 660_000), $this->balance('vat_input'));
        $untagged = JournalLine::query()->forContext($this->ledger)->whereIn('gl_account_id', [$this->gl('vat_output')->id, $this->gl('vat_input')->id])->whereNull('tax_code_id')->count();
        $this->assertSame(0, $untagged, 'no line on a VAT account is uncategorised');
    }

    public function test_the_choice_of_code_on_a_document_decides_the_vat_and_a_purchase_only_code_cannot_be_used_on_a_sale(): void
    {
        $this->setUpIntegration();
        foreach ([['ZA_VAT_STD', 15_000], ['ZA_VAT_ZERO', 0], ['ZA_VAT_EXEMPT', 0]] as [$code, $vat]) {
            $doc = $this->issueInvoice($this->acme, [['description' => $code, 'quantity' => '1', 'unit_cents' => 100_000, 'tax_code' => $code]], '2026-11-03');
            $this->assertSame($vat, (int) $doc->tax_cents, "{$code}: VAT on R1,000.00");
        }
        $json = $this->asOwner()->postJson(route('sales.calculate'), [
            'kind' => 'tax_invoice', 'party_id' => $this->acme->id, 'currency' => 'ZAR', 'issue_date' => '2026-11-03',
            'lines' => [['description' => 'Wrong code', 'quantity' => '1', 'unit_price' => '100.00', 'tax_code' => 'ZA_VAT_DENIED_ENTERTAINMENT']],
        ])->assertOk()->json();
        $this->assertFalse($json['ok']);
        $this->assertStringContainsString('is for purchases and cannot be used on a sale', $json['message']);
    }

    public function test_the_review_queue_catches_wrongly_categorised_entries_and_each_decision_is_kept_with_who_and_why(): void
    {
        $this->runMonth(false);
        $std = $this->codeId('ZA_VAT_STD');
        $denied = $this->codeId('ZA_VAT_DENIED_ENTERTAINMENT');
        $zero = $this->codeId('ZA_VAT_ZERO');
        $ar = $this->gl('ar')->id;

        // a standard-rated sale booked with no VAT; a zero-rated sale that carries VAT; input VAT claimed under a blocked code; a VAT line with no code
        $this->postEntry('manual', 'w1', '2026-11-18', 'Standard sale without VAT', [new PostingLine($ar, debitCents: 50_000), new PostingLine($this->gl('sales')->id, creditCents: 50_000, taxCodeId: $std)]);
        $this->postEntry('manual', 'w2', '2026-11-18', 'Zero-rated sale with VAT', [new PostingLine($ar, debitCents: 11_500), new PostingLine($this->gl('sales')->id, creditCents: 10_000, taxCodeId: $zero), new PostingLine($this->gl('vat_output')->id, creditCents: 1_500, taxCodeId: $zero)]);
        $this->postEntry('manual', 'w3', '2026-11-19', 'Blocked VAT claimed', [new PostingLine($this->gl('6190')->id, debitCents: 20_000, taxCodeId: $denied), new PostingLine($this->gl('vat_input')->id, debitCents: 3_000, taxCodeId: $denied), new PostingLine($this->gl('ap')->id, creditCents: 23_000)]);
        $this->postEntry('manual', 'w4', '2026-11-19', 'VAT with no code', [new PostingLine($this->gl('6190')->id, debitCents: 10_000), new PostingLine($this->gl('vat_input')->id, debitCents: 1_500), new PostingLine($this->gl('ap')->id, creditCents: 11_500)]);

        $page = $this->asOwner()->get(route('tax.vat.review', ['from' => '2026-11-01', 'to' => '2026-11-30']))->assertOk()->getContent();
        $text = preg_replace('/\s+/', ' ', strip_tags($page));
        foreach (['standard-rated sale', 'zero-rated', 'no tax code'] as $needle) {
            $this->assertStringContainsStringIgnoringCase($needle, $text, "the review mentions: {$needle}");
        }

        $queue = app(\App\Services\Tax\VatReviewQueue::class);
        $computed = LedgerContext::run($this->ledger, fn () => app(\App\Services\Tax\Vat201Builder::class)->build($this->ledger, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'), []));
        $issues = collect(LedgerContext::run($this->ledger, fn () => $queue->forPeriod($this->ledger, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'), $computed)));
        $codes = $issues->where('severity', 'error')->pluck('code')->unique()->sort()->values()->all();
        foreach (['standard_without_vat', 'untagged_vat_line', 'denied_input_claimed', 'zero_rated_with_vat'] as $expected) {
            $this->assertContains($expected, $codes, "the queue flags {$expected}");
        }

        // a person resolves one with a note: it is kept, attributed and audited; a note that is too short is refused
        $issue = $issues->firstWhere('code', 'standard_without_vat');
        $body = ['journal_line_id' => $issue['journal_line_id'], 'issue_code' => 'standard_without_vat', 'resolution' => 'accepted'];
        $accountant = $this->memberAs('accountant');
        $this->asMember($accountant)->post(route('tax.vat.review.resolve'), $body + ['note' => 'x'])->assertSessionHasErrors('note');
        $this->asMember($accountant)->post(route('tax.vat.review.resolve'), $body + ['note' => 'Customer is a diplomatic mission, VAT not charged'])->assertSessionHasNoErrors();
        $row = TaxReviewResolution::query()->forContext($this->ledger)->firstOrFail();
        $this->assertSame([$accountant->id, 'accepted'], [(int) $row->resolved_by_user_id, $row->resolution]);
        $this->assertCount(1, $this->auditRows('resolved', 'tax_review'));
        $this->asMember($this->memberAs('bookkeeper'))->post(route('tax.vat.review.resolve'), $body + ['note' => 'A bookkeeper may not decide this'])->assertForbidden();
    }
}
