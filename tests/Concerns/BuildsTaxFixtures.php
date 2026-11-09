<?php

namespace Tests\Concerns;

use App\Models\Accounting\JournalEntry;
use App\Models\Tax\TaxProfile;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\PostingLine;
use App\Services\Accounting\PostingRequest;
use App\Services\Shared\TaxRuleBook;
use App\Services\Tax\TaxProfiles;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Database\Seeders\TaxHolidaysSeeder;
use Database\Seeders\TaxModuleRulesSeeder;

/**
 * Fixtures for the Tax tests: the real South African rules, a company ledger with accounting on, a tax profile, and
 * VAT-bearing entries posted through the real JournalPoster (a sale is Dr AR / Cr Sales / Cr VAT output, a purchase is
 * Dr expense / Dr VAT input / Cr AP, each base and VAT line carrying the tax code).
 */
trait BuildsTaxFixtures
{
    use BuildsAccounting;
    use BuildsControlsFixtures;

    private int $entrySeq = 0;

    protected LedgerContext $tax;

    /** A company (the Controls "Acme" with its six roles) with accounting on and the South African rules loaded. */
    protected function taxLedger(): LedgerContext
    {
        $this->buildAcme();
        $this->seed(TaxModuleRulesSeeder::class);
        $this->seed(TaxHolidaysSeeder::class);
        $this->tax = $this->companyContext($this->acme);
        $this->enableAccounting($this->tax, 'ZAR');

        return $this->tax;
    }

    protected function vatProfile(array $overrides = [], ?LedgerContext $ctx = null): TaxProfile
    {
        $ctx ??= $this->tax;

        return app(TaxProfiles::class)->save($ctx, $overrides + [
            'jurisdiction' => 'ZA', 'legal_entity' => 'company', 'tax_regime' => 'standard', 'vat_registered' => true, 'vat_number' => '4123456789',
            'vat_category' => 'B', 'vat_basis' => 'invoice', 'vat_registered_on' => '2024-01-01', 'vat_filing_channel' => 'efiling',
            'financial_year_end_month' => 2, 'provisional_taxpayer' => true, 'accounting_basis' => 'accrual', 'employs_staff' => true,
        ]);
    }

    protected function taxCodeId(string $code, string $on = '2026-06-01'): int
    {
        return app(TaxRuleBook::class)->forContext(null)->taxCode('ZA', $code, $on)->id;
    }

    /** A sale. Returns the entry. $vat null = 15 percent rounded; pass 0 for no VAT line. */
    protected function sale(string $date, int $net, string $code = 'ZA_VAT_STD', ?int $vat = null, ?LedgerContext $ctx = null, bool $tagBase = true): JournalEntry
    {
        $ctx ??= $this->tax;
        $codeId = $this->taxCodeId($code, $date);
        $vat ??= $code === 'ZA_VAT_STD' || $code === 'ZA_VAT_CAPITAL' ? intdiv($net * 15 + 50, 100) : 0;

        $lines = [new PostingLine('ar', debitCents: $net + $vat)];
        $lines[] = new PostingLine('sales', creditCents: $net, taxCodeId: $tagBase ? $codeId : null);
        if ($vat > 0) {
            $lines[] = new PostingLine('vat_output', creditCents: $vat, taxCodeId: $codeId);
        }

        return $this->postEntry($ctx, $date, 'invoice', 'Sale '.$code, $lines);
    }

    /** A purchase on an expense account (default "Rent"). */
    protected function purchase(string $date, int $net, string $code = 'ZA_VAT_STD', ?int $vat = null, ?LedgerContext $ctx = null, string $expense = 'Rent', ?int $partyId = null): JournalEntry
    {
        $ctx ??= $this->tax;
        $codeId = $this->taxCodeId($code, $date);
        $vat ??= in_array($code, ['ZA_VAT_STD', 'ZA_VAT_CAPITAL'], true) ? intdiv($net * 15 + 50, 100) : 0;
        $expenseId = $this->glByName($expense, $ctx);

        $lines = [new PostingLine($expenseId, debitCents: $net, taxCodeId: $codeId, partyId: $partyId)];
        if ($vat > 0) {
            $lines[] = new PostingLine('vat_input', debitCents: $vat, taxCodeId: $codeId, partyId: $partyId);
        }
        $lines[] = new PostingLine('ap', creditCents: $net + $vat, partyId: $partyId);

        return $this->postEntry($ctx, $date, 'bill', 'Purchase '.$code, $lines);
    }

    /** A credit note issued: the mirror of a sale (Dr Sales, Dr VAT output, Cr AR). */
    protected function creditNote(string $date, int $net, int $vat, ?LedgerContext $ctx = null): JournalEntry
    {
        $ctx ??= $this->tax;
        $codeId = $this->taxCodeId('ZA_VAT_STD', $date);

        return $this->postEntry($ctx, $date, 'credit_note', 'Credit note', [
            new PostingLine('sales', debitCents: $net, taxCodeId: $codeId),
            new PostingLine('vat_output', debitCents: $vat, taxCodeId: $codeId),
            new PostingLine('ar', creditCents: $net + $vat),
        ]);
    }

    protected function postEntry(LedgerContext $ctx, string $date, string $type, string $memo, array $lines): JournalEntry
    {
        return LedgerContext::run($ctx, fn () => app(JournalPoster::class)->post(new PostingRequest(CarbonImmutable::parse($date), $type, 'fx'.(++$this->entrySeq), 1, $memo, $lines)));
    }

    protected function glByName(string $name, LedgerContext $ctx): int
    {
        return \App\Models\Accounting\GlAccount::query()->forContext($ctx)->where('name', $name)->firstOrFail()->id;
    }
}
