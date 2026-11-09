<?php

namespace App\Services\Sales\Gateways;

use App\Models\Sales\SalesDocument;
use App\Services\Sales\SalesProfiles;

/**
 * "Pay by bank transfer": the banking details on the invoice, and a person records the payment when it arrives.
 * It does not move or confirm money.
 */
final class ManualGateway implements PaymentGateway
{
    public function __construct(private readonly SalesProfiles $profiles) {}

    public function key(): string
    {
        return 'manual';
    }

    public function label(): string
    {
        return 'Bank transfer (recorded by hand)';
    }

    public function takesOnlinePayments(): bool
    {
        return false;
    }

    public function instructions(SalesDocument $document): string
    {
        $profile = $this->profiles->for($document->ledgerContext());
        $lines = array_filter([
            $profile->payment_instructions,
            $profile->bank_name ? 'Bank: '.$profile->bank_name : null,
            $profile->bank_account_holder ? 'Account holder: '.$profile->bank_account_holder : null,
            $profile->bank_account_number ? 'Account number: '.$profile->bank_account_number : null,
            $profile->bank_branch_code ? 'Branch code: '.$profile->bank_branch_code : null,
            $document->number ? 'Reference: '.$document->number : null,
        ]);

        return $lines === [] ? 'Add your banking details under Business profile so customers know how to pay.' : implode("\n", $lines);
    }

    public function checkoutUrl(SalesDocument $document): string
    {
        throw new GatewayNotImplemented('Online payments are not available. Payment is by bank transfer and a person records it when it arrives.');
    }
}
