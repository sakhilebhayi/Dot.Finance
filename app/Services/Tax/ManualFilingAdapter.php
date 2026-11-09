<?php

namespace App\Services\Tax;

use App\Models\Tax\TaxReturn;
use App\Services\Tax\Exceptions\NotSubmittableElectronically;
use App\Support\Money;

/** The only filing path: a person files on SARS eFiling (or at a branch) and then records the SARS reference here. */
final class ManualFilingAdapter implements FilingAdapter
{
    public function name(): string
    {
        return 'manual';
    }

    public function canSubmitElectronically(): bool
    {
        return false;
    }

    public function instructions(TaxReturn $return): array
    {
        if ($return->kind === 'emp201') {
            return [
                ['step' => 'Open SARS eFiling and sign in as the employer.', 'detail' => 'Use the employer\'s eFiling profile. Dot.Finance has no access to it and never will.'],
                ['step' => 'Open the EMP201 for '.$return->period_label.'.', 'detail' => 'Select the tax period '.$return->period_start->format('Y-m').'. The employer reference is the PAYE number on your profile.'],
                ['step' => 'Capture the PAYE, SDL, UIF and ETI amounts from the working paper.', 'detail' => 'The working paper lists each amount against the line of the EMP201. Check the totals against eFiling before you submit.'],
                ['step' => 'Submit, then pay with the payment reference (PRN) eFiling shows.', 'detail' => 'Payment is made through eFiling or by EFT with the PRN. It is not done in Dot.Finance.'],
                ['step' => 'Come back and record the submission here.', 'detail' => 'Enter the SARS reference, the date you filed, and attach the proof of submission. Then record the payment.'],
            ];
        }

        return [
            ['step' => 'Open SARS eFiling and sign in as the taxpayer or registered representative.', 'detail' => 'Dot.Finance cannot log in for you. SARS has no open API; filing through software needs SARS\'s Independent Software Vendor programme, which this product has not joined.'],
            ['step' => 'Open the VAT201 for '.$return->period_label.'.', 'detail' => 'Select the tax period ending '.$return->period_end->format('j F Y').' ('.($return->channel === 'manual' ? 'manual filing' : 'eFiling').').'],
            ['step' => 'Capture each field from the working paper.', 'detail' => 'Fields 1 to 20 use SARS\'s own numbers. Field 1 and 1A are VAT-inclusive. Output tax now '.Money::format((int) $return->output_tax_cents, 'ZAR').', input tax '.Money::format((int) $return->input_tax_cents, 'ZAR').', '.((int) $return->payable_cents >= 0 ? 'payable ' : 'refundable ').Money::format(abs((int) $return->payable_cents), 'ZAR').'. Check eFiling\'s totals against these before submitting.'],
            ['step' => 'Submit, then pay with the payment reference (PRN) eFiling shows.', 'detail' => 'A payment made outside eFiling follows the other due date shown on the calendar.'],
            ['step' => 'Come back and record the submission here.', 'detail' => 'Enter the SARS reference, the date, and attach the proof. Once recorded the figures are frozen. Then record the payment.'],
        ];
    }

    public function submit(TaxReturn $return): never
    {
        throw new NotSubmittableElectronically('Dot.Finance does not submit anything to SARS. File this return yourself on eFiling or at a branch, then record the SARS reference here.');
    }
}
