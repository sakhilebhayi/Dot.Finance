<?php

namespace App\Services\Sales;

use App\Models\Sales\SalesDocument;
use App\Models\Sales\SalesDocumentLine;
use App\Models\Sales\SalesProfile;
use App\Models\Shared\Party;
use App\Services\Shared\TaxRuleBook;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * "Is this a valid tax invoice?" as a list a person can read, built from the VAT Act requirements recorded in
 * docs/R44_TAX_RESEARCH.md section 2.7 (VAT 404 s13.1, Act s20). The thresholds come from the rule book, not from
 * constants here. Each item is {key, label, ok, blocking, detail}. A VAT-registered business cannot issue an
 * invoice with a failing blocking item. Items marked advice never block.
 *
 * It describes what is on the document; it does not give tax advice. The wording of each detail says what to fix.
 */
final class TaxInvoiceChecklist
{
    public function __construct(private readonly TaxRuleBook $rules) {}

    /**
     * @param  Party|null  $party  the customer as they are now (the snapshot is used for an issued document)
     * @return array{items: list<array{key: string, label: string, ok: bool, blocking: bool, detail: string}>, valid: bool, applies: bool}
     */
    public function evaluate(SalesDocument $document, SalesProfile $profile, ?Party $party, ?CarbonImmutable $on = null): array
    {
        $registered = (bool) ($document->exists && $document->sealed_at !== null ? $document->vat_registered : $profile->vat_registered);
        $applies = $registered && in_array($document->kind, ['tax_invoice', 'credit_note', 'debit_note'], true);
        $on ??= CarbonImmutable::parse(($document->issue_date ?? now())->toDateString());
        $kind = $document->kind;
        $noun = match ($kind) {
            'credit_note' => 'credit note', 'debit_note' => 'debit note', default => 'tax invoice',
        };

        $items = [];
        $add = function (string $key, string $label, bool $ok, string $detail, bool $blocking = true) use (&$items) {
            $items[] = ['key' => $key, 'label' => $label, 'ok' => $ok, 'blocking' => $blocking, 'detail' => $detail];
        };

        $customer = $document->sealed_at !== null ? ($document->customer_snapshot ?? []) : $this->customerArray($party);
        $supplier = $document->sealed_at !== null ? ($document->supplier_snapshot ?? []) : $this->supplierArray($profile);
        $lines = $document->relationLoaded('lines') ? $document->lines : ($document->exists ? $document->lines()->get() : collect());
        $total = (int) $document->total_cents;

        $add('heading', 'Headed "'.ucfirst($noun).'"', true, 'The document is headed "'.$document->title($registered).'".');
        $add('supplier_name', 'Your name', filled($supplier['name'] ?? null), filled($supplier['name'] ?? null) ? ($supplier['name']) : 'Add your legal or trading name under Business profile.');
        $add('supplier_address', 'Your address', ! empty($supplier['address']), ! empty($supplier['address']) ? 'On the document.' : 'Add your address under Business profile.');
        $vatNo = $supplier['vat_number'] ?? null;
        $add('supplier_vat', 'Your VAT number', filled($vatNo), filled($vatNo) ? $vatNo : 'Add your VAT registration number under Business profile.');
        if (filled($vatNo) && ! preg_match('/^4\d{9}$/', (string) $vatNo)) {
            $add('supplier_vat_format', 'Your VAT number looks like a SARS number', false, 'SARS VAT numbers have ten digits and start with 4. Check it (format check only; this does not confirm it with SARS).', false);
        }

        $add('customer_name', 'Customer name', filled($customer['name'] ?? null), filled($customer['name'] ?? null) ? $customer['name'] : 'The customer has no name.');

        // The research threshold: a full invoice (customer name, address and VAT number) above it.
        $threshold = $this->threshold($document->tax_jurisdiction ?? $profile->tax_jurisdiction ?? 'ZA', $on);
        $needsFull = $kind !== 'tax_invoice' || $total >= $threshold || $this->hasZeroRated($lines);
        $addressOk = ! empty($customer['address']);
        $add('customer_address', 'Customer address', ! $needsFull || $addressOk,
            $addressOk ? 'On the document.' : ($needsFull ? 'Needed on a full tax invoice (the total is at or above the R'.number_format($threshold / 100, 0, '.', ',').' limit, or a line is zero-rated). Add the customer\'s address.' : 'Not needed below the R'.number_format($threshold / 100, 0, '.', ',').' limit.'));
        $customerVat = $customer['tax_number'] ?? null;
        $add('customer_vat', 'Customer VAT number', true, filled($customerVat) ? $customerVat : 'None recorded. If your customer is a VAT vendor their VAT number must be on a full tax invoice, so add it to the customer.', false);

        $add('date', 'Date of issue', $document->issue_date !== null, $document->issue_date ? $document->issue_date->toDateString() : 'Set the issue date.');
        $add('number', 'Unique sequential number', true, $document->number ?? 'The next number in your sequence is given when you issue, with no gaps.');

        $described = $lines->isNotEmpty() && $lines->every(fn ($l) => trim((string) $l->description) !== '');
        $add('description', 'Description of every line', $described, $described ? 'Every line is described.' : 'Every line needs a description.');
        $quantified = $lines->isNotEmpty() && $lines->every(fn ($l) => (int) $l->quantity_e4 > 0);
        $add('quantity', 'Quantity on every line', $quantified, $quantified ? 'Every line has a quantity.' : 'Every line needs a quantity.');
        $add('amounts', 'Value excluding VAT, VAT, and total', $lines->isNotEmpty() && $total > 0, $lines->isNotEmpty() && $total > 0 ? 'All three are shown, worked out by the server.' : 'The document has no value yet.');

        if (in_array($kind, ['credit_note', 'debit_note'], true)) {
            $add('reason', 'Reason for the '.$noun, filled($document->reason), filled($document->reason) ? 'Given.' : 'Say why (goods returned, price changed, supply cancelled).');
            $parent = $document->parent;
            $add('original', 'Identifies the original invoice', $parent !== null && $parent->number !== null, $parent?->number !== null ? 'Corrects '.$parent->number.' of '.$parent->issue_date->toDateString().'.' : 'The original invoice must be named by number and date.');
        }

        if ($document->supply_date !== null && $document->issue_date !== null) {
            $days = $this->issueWithinDays($document->tax_jurisdiction ?? 'ZA', $on);
            if ($days !== null) {
                $late = (int) $document->supply_date->diffInDays($document->issue_date, false);
                $add('timing', 'Issued within '.$days.' days of the supply', $late <= $days, $late <= $days ? 'Within the limit.' : "The supply was {$late} days before the issue date; a tax invoice should be issued within {$days} days.", false);
            }
        }

        $valid = true;
        foreach ($items as $item) {
            if ($item['blocking'] && ! $item['ok']) {
                $valid = false;
            }
        }

        return ['items' => $items, 'valid' => $valid, 'applies' => $applies];
    }

    /** The full-invoice threshold in cents, from the rule book (R5,000 in the research, flagged at the boundary). */
    public function threshold(string $jurisdiction, CarbonImmutable $on): int
    {
        return $this->rules->find($jurisdiction, 'vat.invoice.full_invoice_threshold_cents', $on)?->cents() ?? 500_000;
    }

    private function issueWithinDays(string $jurisdiction, CarbonImmutable $on): ?int
    {
        return $this->rules->find($jurisdiction, 'vat.invoice.issue_within_days', $on)?->integer();
    }

    /** @param  Collection<int, SalesDocumentLine>  $lines */
    private function hasZeroRated($lines): bool
    {
        return $lines->contains(fn ($l) => $l->tax_code !== null && (int) $l->rate_bp === 0 && $l->tax_code !== 'ZA_VAT_EXEMPT' && $l->tax_code !== 'ZA_VAT_OUT_OF_SCOPE');
    }

    /** @return array<string, mixed> */
    public function customerArray(?Party $party): array
    {
        if ($party === null) {
            return [];
        }

        return [
            'name' => $party->legal_name ?: $party->name, 'trading_name' => $party->name, 'tax_number' => $party->tax_number, 'email' => $party->email,
            'registration_number' => $party->registration_number, 'address' => $this->lines($party->billing_address),
        ];
    }

    /** @return array<string, mixed> */
    public function supplierArray(SalesProfile $profile): array
    {
        return [
            'name' => $profile->legal_name ?: $profile->trading_name, 'trading_name' => $profile->trading_name, 'vat_number' => $profile->vat_number,
            'registration_number' => $profile->registration_number, 'address' => $profile->addressLines(), 'email' => $profile->email, 'phone' => $profile->phone,
        ];
    }

    /** @param  array<string, mixed>|null  $address @return list<string> */
    private function lines(?array $address): array
    {
        $a = $address ?? [];

        $join = fn (array $parts) => implode(', ', array_filter(array_map(fn ($x) => trim((string) $x), $parts), fn ($x) => $x !== ''));

        return array_values(array_filter([$a['line1'] ?? null, $a['line2'] ?? null, $join([$a['suburb'] ?? null, $a['city'] ?? null]), $join([$a['province'] ?? null, $a['postal_code'] ?? null]), $a['country'] ?? null], fn ($l) => $l !== null && trim((string) $l) !== ''));
    }
}
