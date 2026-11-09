<?php

namespace App\Services\Integrations\Emall;

use App\Models\Integrations\EmallSaleLine;
use App\Models\Integrations\InboundEvent;
use App\Services\Shared\Tax\TaxLineInput;
use App\Services\Shared\TaxCalculator;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Receives a validated Dot.Emall delivery for ONE ledger and stages it for review. Nothing is journalled here.
 *
 *  - Idempotent twice over: a delivery id is recorded once per ledger (the same delivery again, byte for byte, is a
 *    replay and changes nothing; the same id with different content is refused as a conflict), and an order item id is
 *    imported once per ledger, ever (a line already staged or posted is left exactly as it is).
 *  - Amounts are recomputed, not trusted: the line total is quantity x unit price minus discount; when a tax code
 *    is named, the VAT is recomputed with the shared TaxCalculator from the same rule book the rest of the app uses
 *    and compared with what was claimed. What cannot be recomputed (the marketplace fee) is taken as claimed and
 *    shown as such. Any difference puts the line in "needs attention" with the reason, and it cannot be posted.
 *
 * @phpstan-type Result array{status: string, received: int, staged: int, needs_attention: int, already_imported: int}
 */
final class EmallSalesImport
{
    public const SOURCE = 'emall';

    public function __construct(private readonly TaxCalculator $tax) {}

    /**
     * @param  array{delivery_id: string, company_uuid: string, currency: string, lines: list<array<string, mixed>>}  $payload
     * @return array<string, mixed>
     */
    public function import(LedgerContext $context, array $payload, string $rawBody): array
    {
        return LedgerContext::run($context, function () use ($context, $payload, $rawBody) {
            $hash = hash('sha256', $rawBody);

            return DB::transaction(function () use ($context, $payload, $hash) {
                $existing = InboundEvent::query()->forContext($context)->where('source', self::SOURCE)->where('external_id', $payload['delivery_id'])->first();

                if ($existing !== null) {
                    return $existing->payload_sha256 === $hash
                        ? ['status' => 'replayed'] + (array) $existing->summary
                        : ['status' => 'conflict'];
                }

                try {
                    // A savepoint, so losing a race to the unique index does not poison this transaction on PostgreSQL.
                    $event = DB::transaction(fn () => InboundEvent::create($context->ownerAttributes() + [
                        'source' => self::SOURCE, 'external_id' => $payload['delivery_id'], 'payload_sha256' => $hash, 'status' => 'received',
                    ]));
                } catch (UniqueConstraintViolationException) {
                    return ['status' => 'replayed']; // a concurrent delivery of the same id won the race
                }

                $counts = ['received' => count($payload['lines']), 'staged' => 0, 'needs_attention' => 0, 'already_imported' => 0];

                foreach ($payload['lines'] as $line) {
                    if (EmallSaleLine::query()->forContext($context)->where('order_item_id', $line['order_item_id'])->exists()) {
                        $counts['already_imported']++;

                        continue;
                    }

                    $row = $this->stage($context, $event, $line, $payload['currency']);
                    $counts[$row->status === 'staged' ? 'staged' : 'needs_attention']++;
                }

                $event->forceFill(['summary' => $counts])->save();

                return ['status' => 'stored'] + $counts;
            });
        });
    }

    /** @param  array<string, mixed>  $line */
    private function stage(LedgerContext $context, InboundEvent $event, array $line, string $defaultCurrency): EmallSaleLine
    {
        $problems = [];
        $quantity = (int) $line['quantity'];
        $unit = (int) $line['unit_price_cents'];
        $discount = (int) ($line['discount_cents'] ?? 0);
        $currency = (string) ($line['currency'] ?? $defaultCurrency);
        $fee = (int) ($line['fee_cents'] ?? 0);

        $gross = $quantity * $unit - $discount;
        if ($gross < 0) {
            $problems[] = 'The discount is larger than the line.';
            $gross = 0;
        }

        if ((int) $line['total_cents'] !== $gross) {
            $problems[] = "The total sent ({$line['total_cents']}) is not quantity x unit price minus discount ({$gross}); the recomputed figure is used.";
        }

        $vat = 0;
        $taxCode = $line['tax_code'] ?? null;
        $claimedVat = isset($line['vat_cents']) ? (int) $line['vat_cents'] : null;

        if ($taxCode !== null) {
            try {
                $result = $this->tax->forLine(new TaxLineInput($unit, $quantity, $taxCode, discountCents: $discount), 'ZA', CarbonImmutable::parse($line['sold_on']), inclusive: true);
                $vat = $result->taxCents;
                if ($claimedVat !== null && abs($claimedVat - $vat) > 1) {
                    $problems[] = "The VAT sent ({$claimedVat}) differs from the VAT worked out for tax code {$taxCode} ({$vat}); the worked-out figure is used.";
                }
            } catch (Throwable) {
                $problems[] = "VAT could not be worked out for tax code {$taxCode} (unknown code, or no rule for that date), so it cannot be verified.";
                $vat = 0;
            }
        } elseif ($claimedVat !== null && $claimedVat > 0) {
            $problems[] = 'VAT was sent without a tax code, so it cannot be verified.';
        }

        if ($fee > $gross) {
            $problems[] = 'The marketplace fee is larger than the sale.';
        }

        return EmallSaleLine::create($context->ownerAttributes() + [
            'inbound_event_id' => $event->id,
            'order_item_id' => (string) $line['order_item_id'],
            'order_ref' => (string) $line['order_ref'],
            'seller_ref' => $line['seller_ref'] ?? null,
            'sold_on' => $line['sold_on'],
            'currency' => $currency,
            'description' => (string) $line['description'],
            'quantity' => $quantity,
            'gross_cents' => $gross,
            'vat_cents' => $vat,
            'fee_cents' => $fee,
            'tax_code' => $taxCode,
            'claimed' => ['total_cents' => (int) $line['total_cents'], 'vat_cents' => $claimedVat, 'fee_cents' => $fee, 'unit_price_cents' => $unit, 'discount_cents' => $discount],
            'status' => $problems === [] ? 'staged' : 'needs_attention',
            'problems' => $problems === [] ? null : $problems,
        ]);
    }
}
