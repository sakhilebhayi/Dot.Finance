<?php

namespace App\Models\Purchases;

use App\Models\Concerns\HasLedgerContext;
use App\Models\Tax\TaxProfile;
use App\Models\User;
use App\Services\Shared\TaxRuleBook;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The purchases settings of one ledger: whether it is a registered VAT vendor (user-provided; until someone says so
 * no input VAT is claimed) and the amount above which a bill or receipt without a document is listed as missing one.
 */
class PurchaseSetting extends Model
{
    use HasLedgerContext;

    protected $fillable = ['user_id', 'team_id', 'vat_registered', 'vat_registered_set_by', 'vat_registered_set_at', 'missing_document_threshold_cents'];

    protected $casts = [
        'vat_registered' => 'boolean',
        'vat_registered_set_at' => 'datetime',
        'missing_document_threshold_cents' => 'integer',
    ];

    public function ledgerReferences(): array
    {
        return [];
    }

    /** The settings row for a ledger (unsaved and empty when nobody has set anything yet). */
    public static function forLedger(?LedgerContext $context = null): self
    {
        $context ??= LedgerContext::current();

        return self::query()->forContext($context)->first() ?? new self($context->ownerAttributes());
    }

    /**
     * True / false once someone has said; null while unknown (then input VAT is not claimed). Once the ledger has a Tax profile
     * that is the answer, so a bill, an invoice and the VAT201 can never disagree about whether the business is a VAT vendor
     * (R-44 integration, D-1204); the setting here is what applies until then.
     */
    public function isVatRegistered(): ?bool
    {
        return $this->taxProfile()?->vat_registered ?? $this->vat_registered;
    }

    /** The Tax profile that overrides this setting, when the ledger has one. */
    public function taxProfile(): ?TaxProfile
    {
        if ($this->user_id === null && $this->team_id === null) {
            return null;
        }

        return TaxProfile::query()->forContext($this->ledgerContext())->first();
    }

    public function setBy(): ?User
    {
        return $this->vat_registered_set_by ? User::query()->find($this->vat_registered_set_by) : null;
    }

    /** The no-document amount: the setting, else the tax rule "no tax invoice needed under" (R50 for South Africa). */
    public function missingDocumentThresholdCents(?CarbonImmutable $on = null): int
    {
        if ($this->missing_document_threshold_cents !== null) {
            return $this->missing_document_threshold_cents;
        }

        return app(TaxRuleBook::class)->find(config('purchases.jurisdiction'), 'vat.invoice.no_invoice_threshold_cents', $on ?? now())?->cents() ?? 5_000;
    }

    public function auditLabel(): string
    {
        return 'Purchases settings';
    }
}
