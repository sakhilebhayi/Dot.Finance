<?php

namespace App\Services\Sales\Gateways;

use App\Models\Sales\SalesDocument;

/**
 * The port an online payment provider would implement. Only ManualGateway exists: gateways are NOT implemented
 * in this release, and nothing in Sales simulates one. A real adapter must (a) create a hosted payment for an
 * invoice, (b) verify the provider's signed callback, and (c) record the payment through ReceiptService with the
 * provider's reference as the idempotency key, so a repeated callback cannot record money twice.
 */
interface PaymentGateway
{
    /** A short stable key, e.g. "manual". */
    public function key(): string;

    public function label(): string;

    /** Whether it can take a payment online right now. The manual gateway never can. */
    public function takesOnlinePayments(): bool;

    /** What to tell the customer about how to pay this document. */
    public function instructions(SalesDocument $document): string;

    /**
     * Start an online payment and return where to send the customer.
     *
     * @throws GatewayNotImplemented
     */
    public function checkoutUrl(SalesDocument $document): string;
}
