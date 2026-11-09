<?php

namespace App\Services\Tax;

use App\Models\Tax\TaxPayrollRun;
use App\Models\Tax\TaxProfile;
use App\Services\Tax\Dto\ObligationAssessment;
use App\Services\Tax\Jurisdiction\JurisdictionRegistry;
use App\Support\Ledger\LedgerContext;
use App\Support\Money;
use App\Support\Shared\Exceptions\NoValidTaxRule;
use Carbon\CarbonImmutable;

/**
 * Decides which taxes apply to THIS business and shows its working. It never assumes that every business has every
 * tax: a business with no employees has no EMP201, a sole proprietor has no ITR14, a business under the registration
 * threshold has no VAT201 (and is told voluntary registration exists). Each answer carries the facts it used, how each
 * fact was obtained (calculated, estimate, you entered) and the rule versions behind any threshold.
 */
final class ObligationEngine
{
    public function __construct(
        private readonly TaxRules $rules,
        private readonly TurnoverMeasure $turnover,
        private readonly JurisdictionRegistry $jurisdictions,
    ) {}

    /** @return list<ObligationAssessment> */
    public function assess(LedgerContext $ctx, ?TaxProfile $profile, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::today();

        if ($profile === null) {
            return [new ObligationAssessment('profile', 'Tax profile', 'review', 'Tell us about the business first.',
                ['No tax profile has been completed for this ledger, so we cannot say which taxes apply. Nothing is assumed.'], [], [], 'high')];
        }

        $pack = $this->jurisdictions->get($profile->jurisdiction);
        if (! $pack->isConfigured()) {
            return [new ObligationAssessment('profile', 'Tax profile', 'not_configured', "{$pack->name()} is not configured.",
                ["No tax rules have been entered for {$pack->name()}, so Dot.Finance calculates nothing for it. Nothing is assumed."], [], [], 'high')];
        }

        $rules = $this->rules->session($ctx, $profile->jurisdiction);
        $out = [$this->vat($ctx, $profile, $rules, $asOf)];
        array_push($out, ...$this->incomeTax($profile, $rules, $asOf));
        array_push($out, ...$this->employment($ctx, $profile, $rules, $asOf));
        $out[] = $this->dividends($profile);
        $out[] = $this->records($rules, $asOf);

        return $out;
    }

    private function vat(LedgerContext $ctx, TaxProfile $p, RuleSession $rules, CarbonImmutable $asOf): ObligationAssessment
    {
        $m = $this->turnover->trailingTwelveMonths($ctx, $asOf);
        $facts = [['label' => 'Taxable supplies, last 12 months ('.CarbonImmutable::parse($m['from'])->format('j M Y').' to '.CarbonImmutable::parse($m['to'])->format('j M Y').')', 'value' => Money::format($m['cents'], 'ZAR').' from the '.$m['source'], 'basis' => TaxBasis::ESTIMATE]];

        try {
            $compulsory = $rules->rule('vat.registration.compulsory_threshold_cents', $asOf)->cents();
            $voluntaryRule = $rules->rule('vat.registration.voluntary_threshold_cents', $asOf);
            $voluntary = $voluntaryRule->cents();
            $window = $rules->rule('vat.registration.window_days', $asOf)->integer();
        } catch (NoValidTaxRule $e) {
            return new ObligationAssessment('vat', 'VAT', 'review', 'The VAT thresholds could not be read.', [$e->getMessage()], $facts, [], 'low');
        }

        $facts[] = ['label' => 'Compulsory registration threshold', 'value' => 'more than '.Money::format($compulsory, 'ZAR'), 'basis' => TaxBasis::CALCULATED];
        $facts[] = ['label' => 'Voluntary registration threshold', 'value' => 'more than '.Money::format($voluntary, 'ZAR'), 'basis' => TaxBasis::CALCULATED];
        $confidence = $rules->lowestConfidence();
        $guidance = 'SARS guidance, confirm: the thresholds were announced in the Budget of 25 February 2026 and applied from 1 April 2026; their legal status is not fully settled in our sources.';
        $reasoning = $m['notes'];

        if ($p->vat_registered) {
            $period = $p->vat_category ? "category {$p->vat_category}" : 'a period category you have not entered yet';
            $reasoning[] = "You told us the business is VAT-registered (".($p->vat_number ?: 'VAT number not entered').") on the {$p->vat_basis} basis, so a VAT201 return is due for every tax period ({$period}).";
            if ($m['cents'] < $voluntary) {
                $reasoning[] = 'Taxable supplies for the last 12 months are below the new voluntary threshold of '.Money::format($voluntary, 'ZAR').'. SARS does not cancel existing registrations automatically; a vendor below the threshold may apply to cancel (cancelling can trigger exit VAT).';
            }
            if (! $p->vat_number) {
                $reasoning[] = 'Enter the VAT number in the profile: it appears on the return and on the supplier checks.';
            }
            $reasoning[] = $guidance;

            return new ObligationAssessment('vat', 'VAT', 'applies', 'VAT201 returns apply: you are registered.', $reasoning, $facts, $this->versions($rules), $confidence, true);
        }

        if ($m['cents'] > $compulsory) {
            $reasoning[] = 'Taxable supplies of '.Money::format($m['cents'], 'ZAR').' are above the compulsory registration threshold of '.Money::format($compulsory, 'ZAR').". A business that passes it must register within {$window} days. Registration appears to be required: check with SARS or your practitioner.";
            $reasoning[] = $guidance;

            return new ObligationAssessment('vat', 'VAT', 'review', 'Compulsory VAT registration appears to apply. Not registered yet.', $reasoning, $facts, $this->versions($rules), $confidence);
        }

        if ($m['cents'] > $voluntary) {
            $reasoning[] = 'You recorded '.Money::format($m['cents'], 'ZAR').' of taxable supplies in the last 12 months. The compulsory registration threshold is '.Money::format($compulsory, 'ZAR').' (SARS guidance, confirm): registration is not required. It is above '.Money::format($voluntary, 'ZAR').', so voluntary registration is available.';
            $reasoning[] = 'A business with only exempt supplies cannot register voluntarily.';
            $reasoning[] = $guidance;

            return new ObligationAssessment('vat', 'VAT', 'optional', 'VAT registration is not required. Voluntary registration is available.', $reasoning, $facts, $this->versions($rules), $confidence);
        }

        $reasoning[] = 'You recorded '.Money::format($m['cents'], 'ZAR').' of taxable supplies in the last 12 months. That is below the voluntary threshold of '.Money::format($voluntary, 'ZAR').', so VAT registration is not required and not available yet (SARS guidance, confirm).';
        $reasoning[] = $guidance;

        return new ObligationAssessment('vat', 'VAT', 'not_required', 'VAT is not required: supplies are below the registration thresholds.', $reasoning, $facts, $this->versions($rules), $confidence);
    }

    /** @return list<ObligationAssessment> */
    private function incomeTax(TaxProfile $p, RuleSession $rules, CarbonImmutable $asOf): array
    {
        $out = [];
        $entity = $p->entityLabel();
        $facts = [['label' => 'Legal entity', 'value' => $entity, 'basis' => TaxBasis::ENTERED], ['label' => 'Financial year end', 'value' => CarbonImmutable::create(2000, $p->financial_year_end_month, 1)->format('F'), 'basis' => TaxBasis::ENTERED]];

        if ($p->tax_regime === 'turnover_tax') {
            $out[] = new ObligationAssessment('turnover_tax', 'Turnover tax', 'review', 'You are on the turnover tax regime.',
                ['Turnover tax replaces income tax, provisional tax, capital gains tax and dividends tax for a qualifying micro business (SARS Budget 2026 Tax Guide). Its own returns and dates are not modelled in Dot.Finance: confirm them with SARS or your practitioner. Annual and provisional income-tax deadlines are therefore not generated.'],
                $facts, $this->versions($rules), 'medium');

            return $out;
        }

        switch ($p->legal_entity) {
            case 'company':
            case 'close_corporation':
                $out[] = new ObligationAssessment('itr14', 'Company income tax return (ITR14)', 'applies', 'An ITR14 is due within 12 months of the financial year end.',
                    ["A {$entity} is a company for income tax. It files an ITR14 each year within 12 months of its financial year end.", 'The rate resolves from the rule book by the year-of-assessment end date (27 percent for years ending 1 April 2023 to 31 March 2027).'], $facts, $this->versions($rules), 'high', true);
                $out[] = new ObligationAssessment('provisional', 'Provisional tax (IRP6)', 'applies', 'Provisional tax applies: companies are provisional taxpayers automatically.',
                    array_merge(['Companies are provisional taxpayers automatically (SARS provisional tax guide, rev 28). Payments fall 6 months into the year, at year end, and an optional top-up after year end.'], $p->provisional_taxpayer ? [] : ['Your profile does not have the provisional taxpayer flag set: it should be.']),
                    $facts, $this->versions($rules), 'high', true);
                break;

            case 'sole_proprietor':
                $out[] = new ObligationAssessment('itr12', 'Personal income tax return (ITR12)', 'applies', 'An ITR12 is filed each tax year: business profit is taxed as personal income.',
                    ['A sole proprietor is taxed as an individual, so there is no separate business return. The tax year runs 1 March to the end of February.'], $facts, $this->versions($rules), 'high', true);
                $out[] = new ObligationAssessment('provisional', 'Provisional tax (IRP6)', 'applies', 'Provisional tax applies: you have income that is not salary.',
                    ['Individuals are provisional taxpayers if they have non-remuneration income, such as business profit (SARS provisional tax guide, rev 28), with exclusions for small amounts that are not modelled here.'], $facts, $this->versions($rules), 'medium', true);
                break;

            case 'partnership':
                $out[] = new ObligationAssessment('itr12', 'Partners\' income tax returns (ITR12)', 'review', 'A partnership is not taxed itself: each partner is.',
                    ['Each partner declares their share of the partnership profit on their own return. Dot.Finance does not compute partners\' tax; the partnership profit is available from the profit and loss report.'], $facts, [], 'medium');
                break;

            case 'trust':
                $out[] = new ObligationAssessment('itr12', 'Trust income tax return (ITR12T)', 'applies', 'A trust files its own income tax return.',
                    ['A trust (other than a special trust) is taxed at a flat 45 percent (SARS Budget 2026 Tax Guide). The tax year runs 1 March to the end of February.', 'Whether the trust is a provisional taxpayer is not decided here: confirm with your practitioner.'], $facts, $this->versions($rules), 'medium', true);
                break;

            case 'non_profit':
                $out[] = new ObligationAssessment('np_exemption', 'Non-profit income tax position', 'review', 'A non-profit organisation may be exempt, but that depends on its SARS approval.',
                    ['Exemption depends on SARS approval as a public benefit organisation or recognised exempt body, which Dot.Finance cannot see. Confirm with SARS or your practitioner. No income tax estimate is made.'], $facts, [], 'low');
                break;
        }

        return $out;
    }

    /** @return list<ObligationAssessment> */
    private function employment(LedgerContext $ctx, TaxProfile $p, RuleSession $rules, CarbonImmutable $asOf): array
    {
        if (! $p->employs_staff) {
            return [new ObligationAssessment('emp201', 'Employer taxes (EMP201, EMP501)', 'not_required', 'No employer taxes: you told us the business has no employees.',
                ['The profile says the business does not employ staff, so PAYE, UIF, SDL and the EMP201 and EMP501 declarations do not apply. If you start employing people you must register as an employer: change the profile then.'], [['label' => 'Employs staff', 'value' => 'No', 'basis' => TaxBasis::ENTERED]], [], 'high')];
        }

        $facts = [['label' => 'Employs staff', 'value' => 'Yes', 'basis' => TaxBasis::ENTERED]];
        $out = [];
        $reasoning = ['Employers pay PAYE, UIF and SDL monthly with the EMP201 by the 7th of the following month (the business day before if the 7th is a weekend or public holiday), and reconcile twice a year with the EMP501.'];
        if (! $p->paye_ref) {
            $reasoning[] = 'No PAYE reference is recorded yet. Enter it in the profile.';
        }
        $out[] = new ObligationAssessment('emp201', 'Employer taxes (EMP201, EMP501)', 'applies', 'EMP201 every month and the EMP501 reconciliations apply.', $reasoning, $facts, $this->versions($rules), 'high', true);

        // SDL: exempt when the annual payroll is under the threshold
        try {
            $threshold = $rules->rule('sdl.payroll_exemption_threshold_cents', $asOf)->cents();
            $payroll = (int) TaxPayrollRun::query()->forContext($ctx)->where('period_month', '>', $asOf->subYear()->startOfMonth()->toDateString())->where('period_month', '<=', $asOf->toDateString())->sum('gross_pay_cents');
            $runs = TaxPayrollRun::query()->forContext($ctx)->where('period_month', '>', $asOf->subYear()->startOfMonth()->toDateString())->where('period_month', '<=', $asOf->toDateString())->count();
            $sdlFacts = [['label' => 'Recorded payroll, last 12 months', 'value' => $runs === 0 ? 'none recorded yet' : Money::format($payroll, 'ZAR').' over '.$runs.' month(s)', 'basis' => TaxBasis::ESTIMATE], ['label' => 'SDL exemption threshold', 'value' => 'annual payroll under '.Money::format($threshold, 'ZAR'), 'basis' => TaxBasis::CALCULATED]];
            if ($runs === 0) {
                $out[] = new ObligationAssessment('sdl', 'Skills development levy (SDL)', 'review', 'SDL: no payroll recorded yet, so the exemption cannot be tested.', ['SDL is 1 percent of remuneration, but an employer whose annual payroll is under '.Money::format($threshold, 'ZAR').' is exempt. Record payroll summaries to test this.'], $sdlFacts, $this->versions($rules), 'high');
            } else {
                $annualised = $runs >= 12 ? $payroll : intdiv($payroll * 12, $runs);
                $exempt = $annualised < $threshold;
                $out[] = new ObligationAssessment('sdl', 'Skills development levy (SDL)', $exempt ? 'not_required' : 'applies',
                    $exempt ? 'SDL looks exempt: annual payroll is under the threshold.' : 'SDL applies: annual payroll is at or over the threshold.',
                    ['Recorded payroll annualised is '.Money::format($annualised, 'ZAR').' against a threshold of '.Money::format($threshold, 'ZAR').'. This is an estimate: the law looks at what you reasonably expect to pay over the next 12 months.'], $sdlFacts, $this->versions($rules), 'high');
            }
        } catch (NoValidTaxRule) {
            // the SDL rule is not on record: leave SDL out rather than guess
        }

        $out[] = new ObligationAssessment('coida', 'COIDA return of earnings', $p->coida_ref ? 'applies' : 'review', $p->coida_ref ? 'The yearly COIDA return of earnings applies.' : 'COIDA: employers register with the Compensation Fund; no reference is recorded.',
            ['COIDA is not a SARS tax; Dot.Finance treats it as a reminder and an estimate only. The return of earnings window in 2026 was 1 April to 30 June.'], $facts, [], 'medium', (bool) $p->coida_ref);

        return $out;
    }

    private function dividends(TaxProfile $p): ObligationAssessment
    {
        $company = $p->isCompanyLike();

        return new ObligationAssessment('dividends', 'Dividends tax', $company ? 'review' : 'not_required',
            $company ? 'Dividends tax applies only if the company declares a dividend.' : 'Dividends tax does not apply to this kind of entity.',
            $company ? ['If the company pays a dividend, 20 percent dividends tax is withheld and paid over, due by the end of the month after the month of payment (the payment date is from the researcher\'s knowledge of the Act, low confidence: verify). Dot.Finance does not track dividend declarations, so no deadline is created.'] : ['Only companies pay dividends tax on their distributions.'],
            [], [], $company ? 'low' : 'high');
    }

    private function records(RuleSession $rules, CarbonImmutable $asOf): ObligationAssessment
    {
        $years = $rules->rule('records.retention_years.tax', $asOf)->integer();

        return new ObligationAssessment('records', 'Keeping tax records', 'applies', "Keep tax and VAT records for {$years} years.",
            ["Tax and VAT records must be kept for {$years} years (Tax Administration Act s29 and the VAT Act). The document vault records the date each can be discarded and a legal-hold flag."], [], $this->versions($rules), 'high');
    }

    /** @return list<string> */
    private function versions(RuleSession $rules): array
    {
        return array_map(fn ($v) => $v['version'].' '.$v['key'].' ('.$v['confidence'].')', $rules->versions());
    }
}
