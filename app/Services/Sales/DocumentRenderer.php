<?php

namespace App\Services\Sales;

use App\Models\Sales\SalesDocument;
use App\Models\Shared\Party;
use App\Services\Sales\Gateways\ManualGateway;
use App\Services\Shared\FxMath;
use App\Support\Money;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Str;

/**
 * Turns a document into something to read, print or send. The same view serves the browser (print to PDF with
 *
 * @page rules) and Dompdf (a real PDF file). An issued document is drawn from the snapshots taken when it was
 * issued, so a later change to the customer or to the business profile never changes what was issued.
 */
final class DocumentRenderer
{
    public function __construct(private readonly SalesProfiles $profiles, private readonly TaxInvoiceChecklist $checklist, private readonly ManualGateway $manual) {}

    /** @return array<string, mixed> */
    public function data(SalesDocument $document, bool $pdf = false): array
    {
        $context = $document->ledgerContext();
        $profile = $this->profiles->for($context);
        $party = Party::query()->forContext($context)->withTrashed()->find($document->party_id);
        $supplier = $document->isSealed() ? ($document->supplier_snapshot ?? []) : $this->checklist->supplierArray($profile);
        $customer = $document->isSealed() ? ($document->customer_snapshot ?? []) : $this->checklist->customerArray($party);
        $lines = $document->lines()->get();

        $summary = [];
        foreach ($lines as $l) {
            $key = ($l->tax_code ?? 'none').'@'.$l->rate_bp;
            $summary[$key] ??= ['code' => $l->tax_code, 'rate_bp' => (int) $l->rate_bp, 'net' => 0, 'tax' => 0];
            $summary[$key]['net'] += (int) $l->net_cents;
            $summary[$key]['tax'] += (int) $l->tax_cents;
        }

        $watermark = match (true) {
            $document->isVoid() => 'VOID',
            ! $document->isSealed() => 'DRAFT',
            default => null,
        };

        return [
            'document' => $document, 'lines' => $lines, 'profile' => $profile, 'supplier' => $supplier, 'customer' => $customer,
            'party' => $party, 'title' => $document->title(), 'summary' => array_values($summary), 'watermark' => $watermark,
            'logo' => $this->profiles->logoDataUri($profile), 'brand' => $this->brand($profile->brand_color),
            'payment_instructions' => $document->isReceivable() ? $this->manual->instructions($document) : null,
            'parent' => $document->parent_id !== null ? $document->parent : null,
            'pdf' => $pdf, 'vat' => (bool) $document->vat_registered,
            'rate' => $document->fx_rate_e8 !== null ? FxMath::formatRate((int) $document->fx_rate_e8) : null,
        ];
    }

    public function html(SalesDocument $document, bool $pdf = false): string
    {
        return view('sales.print.document', $this->data($document, $pdf))->render();
    }

    public function pdf(SalesDocument $document): string
    {
        return $this->toPdf($this->html($document, true));
    }

    public function filename(SalesDocument $document): string
    {
        return Str::slug(($document->number ?? 'draft-'.$document->id).' '.$document->title()).'.pdf';
    }

    /** @param  array<string, mixed>  $statement */
    public function statementHtml(array $statement, bool $pdf = false): string
    {
        $context = $statement['party']->ledgerContext();
        $profile = $this->profiles->for($context);

        return view('sales.print.statement', [
            'statement' => $statement, 'profile' => $profile, 'supplier' => $this->checklist->supplierArray($profile), 'customer' => $this->checklist->customerArray($statement['party']),
            'logo' => $this->profiles->logoDataUri($profile), 'brand' => $this->brand($profile->brand_color), 'pdf' => $pdf,
        ])->render();
    }

    /** @param  array<string, mixed>  $statement */
    public function statementPdf(array $statement): string
    {
        return $this->toPdf($this->statementHtml($statement, true));
    }

    private function toPdf(string $html): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', [storage_path(), resource_path()]);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /** A brand colour that is safe to print: six hex digits, dark enough for white text; else graphite. */
    private function brand(?string $hex): string
    {
        if ($hex === null || ! preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) {
            return '#1f2937';
        }
        [$r, $g, $b] = [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
        // relative luminance, integer approximation: a pale colour would make white text unreadable, so it is replaced
        $luma = (2126 * $r + 7152 * $g + 722 * $b) / 10000;

        return $luma > 150 ? '#1f2937' : strtolower($hex);
    }

    public static function money(int $cents, string $currency): string
    {
        return Money::format($cents, $currency);
    }
}
