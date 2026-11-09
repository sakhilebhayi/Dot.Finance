<?php

namespace App\Models\Tax;

use App\Casts\DateOnly;
use App\Models\Concerns\HasLedgerContext;
use Illuminate\Database\Eloquent\Model;

/**
 * A ledger's tax and financial profile: the facts the obligation engine reasons from. One row per ledger.
 * It records what the owner says about the business (registrations, references, year end); nothing here is
 * verified against SARS.
 */
class TaxProfile extends Model
{
    use HasLedgerContext;

    public const ENTITIES = [
        'sole_proprietor' => 'Sole proprietor',
        'company' => 'Company (Pty) Ltd',
        'close_corporation' => 'Close corporation',
        'trust' => 'Trust',
        'partnership' => 'Partnership',
        'non_profit' => 'Non-profit organisation',
    ];

    protected $fillable = [
        'user_id', 'team_id', 'jurisdiction', 'legal_entity', 'tax_regime', 'vat_registered', 'vat_number', 'vat_category',
        'vat_basis', 'vat_registered_on', 'vat_filing_channel', 'income_tax_ref', 'paye_ref', 'sdl_ref', 'uif_ref', 'coida_ref',
        'financial_year_end_month', 'provisional_taxpayer', 'accounting_basis', 'employs_staff', 'individual_age_band',
        'shareholders_natural_persons', 'wizard_completed_at', 'updated_by_user_id',
    ];

    protected $casts = [
        'vat_registered' => 'boolean',
        'vat_registered_on' => DateOnly::class,
        'provisional_taxpayer' => 'boolean',
        'employs_staff' => 'boolean',
        'shareholders_natural_persons' => 'boolean',
        'financial_year_end_month' => 'integer',
        'wizard_completed_at' => 'datetime',
    ];

    public function isCompanyLike(): bool
    {
        return in_array($this->legal_entity, ['company', 'close_corporation'], true);
    }

    /** Individuals and trusts run on the 1 March to end-February tax year; companies on their own financial year. */
    public function usesIndividualTaxYear(): bool
    {
        return in_array($this->legal_entity, ['sole_proprietor', 'partnership', 'trust'], true);
    }

    public function entityLabel(): string
    {
        return self::ENTITIES[$this->legal_entity] ?? $this->legal_entity;
    }
}
