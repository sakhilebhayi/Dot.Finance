<?php

namespace App\Services\Purchases;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\LedgerSetting;
use App\Models\Category;
use App\Models\Shared\Currency;
use App\Services\Accounting\Chart;
use App\Services\Accounting\Exceptions\AccountingNotReady;
use App\Services\Shared\FxMath;
use App\Services\Shared\FxService;
use App\Support\Ledger\LedgerContext;
use App\Support\Shared\Exceptions\NoFxRate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Small facts about the ledger Purchases needs everywhere: is accounting on, what is the base currency, how does an amount
 * in another currency convert, which chart accounts a bill may be charged to.
 */
class PurchaseLedger
{
    public function __construct(private readonly Chart $chart, private readonly FxService $fx) {}

    public function flush(): void
    {
        $this->chart->flush();
    }

    /** Read fresh every time: one cheap query, and it can never go stale when accounting is switched on or the books are closed. */
    public function settings(LedgerContext $context): ?LedgerSetting
    {
        return LedgerSetting::query()->forContext($context)->first();
    }

    public function accountingEnabled(LedgerContext $context): bool
    {
        return $this->settings($context)?->isEnabled() ?? false;
    }

    /** @throws PurchasesException when the books cannot take a posting yet */
    public function requireAccounting(LedgerContext $context): LedgerSetting
    {
        $settings = $this->settings($context);

        if ($settings === null || ! $settings->isEnabled()) {
            throw new PurchasesException('Turn on accounting for this ledger first (Controls > Accounting). Bills are posted to the books when they are approved.', 'accounting_off');
        }

        foreach (['ap', 'vat_input'] as $key) {
            try {
                $account = $this->chart->system($key, $context);
            } catch (AccountingNotReady) {
                throw new PurchasesException("This ledger's chart has no '{$key}' account.", 'chart_incomplete');
            }

            if (! $account->is_active) {
                throw new PurchasesException("The account \"{$account->name}\" is switched off in this ledger's chart. Turn it on (Controls > Chart of accounts) to record supplier bills.", 'account_inactive');
            }
        }

        return $settings;
    }

    public function baseCurrency(LedgerContext $context): ?string
    {
        return $this->settings($context)?->base_currency;
    }

    /** The base currency, or ZAR for a ledger with no accounting settings yet (its cash accounts decide nothing here). */
    public function baseCurrencyOrDefault(LedgerContext $context): string
    {
        return $this->baseCurrency($context) ?? 'ZAR';
    }

    /** An amount in minor units of $currency in base-currency minor units. Same currency passes through; another needs its rate. */
    public function toBase(LedgerContext $context, int $cents, string $currency, ?int $rateE8): int
    {
        $base = $this->baseCurrencyOrDefault($context);

        if (strtoupper($currency) === $base) {
            return $cents;
        }

        if ($rateE8 === null) {
            return $cents;
        }

        return FxMath::convert($cents, $rateE8, Currency::minorUnits($currency), Currency::minorUnits($base));
    }

    /**
     * The rate to use for a document in $currency on $on: the one given, else the stored rate for that day. Null for the base currency.
     *
     * @throws PurchasesException when a foreign document has no rate
     */
    public function rateFor(LedgerContext $context, string $currency, CarbonImmutable $on, ?int $given = null): ?int
    {
        $base = $this->baseCurrencyOrDefault($context);

        if (strtoupper($currency) === $base) {
            return null;
        }

        if ($given !== null) {
            return $given;
        }

        try {
            return $this->fx->forContext($context)->rate(strtoupper($currency), $base, $on)->rateE8;
        } catch (NoFxRate) {
            throw new PurchasesException("There is no exchange rate from {$currency} to {$base} on {$on->toDateString()}. Type the rate you were charged.", 'no_fx_rate');
        }
    }

    /**
     * The chart accounts a bill line may be charged to: active, postable expense accounts, and the asset accounts that hold purchases
     * (prepayments, inventory, fixed assets).
     *
     * @return Collection<int, GlAccount>
     */
    public function chargeableAccounts(LedgerContext $context)
    {
        return GlAccount::query()->forContext($context)->where('is_active', true)->where('is_postable', true)
            ->where(fn ($q) => $q->where('class', 'expense')->orWhere(fn ($w) => $w->where('class', 'asset')->whereIn('subtype', ['prepayment', 'inventory', 'fixed_asset'])))
            ->where(fn ($q) => $q->whereNull('system_key')->orWhereNotIn('system_key', ['fx_realised', 'fx_unrealised', 'rounding']))
            ->orderBy('code')->get();
    }

    public function isChargeable(LedgerContext $context, int $glAccountId): bool
    {
        return $this->chargeableAccounts($context)->contains('id', $glAccountId);
    }

    /** The category supplier payments are filed under in the cash ledger (made on first use). */
    public function supplierPaymentsCategory(LedgerContext $context): Category
    {
        return Category::query()->forContext($context)->where('name', 'Supplier payments')->where('type', 'expense')->first()
            ?? Category::query()->create($context->ownerAttributes() + ['name' => 'Supplier payments', 'type' => 'expense', 'color' => '#4b5563']);
    }
}
