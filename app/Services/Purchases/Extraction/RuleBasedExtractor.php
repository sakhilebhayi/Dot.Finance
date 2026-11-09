<?php

namespace App\Services\Purchases\Extraction;

use App\Models\Shared\Attachment;
use Carbon\CarbonImmutable;

/**
 * Reads the text of a text PDF, a text file or a CSV with fixed patterns (no network, no model). It is deliberately modest:
 * it knows the labels South African invoices use ("Tax Invoice", "VAT Reg No", "Invoice No", "Total", "VAT 15%"), and it says how sure
 * it is. Confidence is "high" only when the figures agree with each other (subtotal + VAT = total); a value found by a label alone is
 * "medium"; a guess (the supplier name is the first line) is "low". Images and scans return nothing and ask for manual entry.
 */
final class RuleBasedExtractor implements Extractor
{
    public function __construct(private readonly PdfText $pdf) {}

    public function name(): string
    {
        return 'rules';
    }

    public function supports(Attachment $attachment): bool
    {
        return in_array($attachment->mime, ['application/pdf', 'text/plain', 'text/csv'], true);
    }

    public function extract(Attachment $attachment, string $bytes): ExtractionResult
    {
        $text = match ($attachment->mime) {
            'application/pdf' => $this->pdf->read($bytes),
            'text/plain', 'text/csv' => mb_convert_encoding($bytes, 'UTF-8', 'UTF-8, ISO-8859-1'),
            default => '',
        };

        $text = mb_substr(str_replace(["\r\n", "\r"], "\n", $text), 0, (int) config('purchases.extraction.max_text_chars', 20000));

        if (trim($text) === '') {
            return new ExtractionResult($this->name(), [], [], [$attachment->mime === 'application/pdf'
                ? 'No text could be read from this PDF (it is probably a scan, or uses fonts this reader cannot decode). Enter it by hand.'
                : 'No text could be read from this file.'], true);
        }

        return $this->parse($text);
    }

    public function parse(string $text): ExtractionResult
    {
        $fields = [];
        $confidence = [];
        $warnings = [];
        $set = function (string $field, mixed $value, string $level) use (&$fields, &$confidence) {
            if ($value !== null && $value !== '') {
                $fields[$field] = $value;
                $confidence[$field] = $level;
            }
        };

        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn ($l) => $l !== ''));

        // type of document
        if (preg_match('/\bcredit\s+note\b/i', $text) === 1) {
            $set('document_type', 'credit_note', 'medium');
        } elseif (preg_match('/\babridged\s+tax\s+invoice\b/i', $text) === 1) {
            $set('document_type', 'abridged_invoice', 'medium');
        } elseif (preg_match('/\btax\s+invoice\b|\bvat\s+invoice\b/i', $text) === 1) {
            $set('document_type', 'tax_invoice', 'medium');
        }

        // supplier VAT number: South African vendor numbers are ten digits starting with 4
        if (preg_match('/vat\s*(?:reg(?:istration)?\.?)?\s*(?:no\.?|number|nr\.?|#)?\s*[:.\-]?\s*(4[\d ]{9,13})/i', $text, $m) === 1) {
            $digits = preg_replace('/\D/', '', $m[1]);
            if (strlen($digits) === 10) {
                $set('supplier_vat_number', $digits, 'medium');
            }
        }

        // invoice number
        if (preg_match('/(?:tax\s+)?(?:invoice|inv|credit\s+note)\s*(?:no\.?|number|nr\.?|#)\s*[:.\-]?\s*([A-Z0-9][A-Z0-9\-\/]{2,30})/i', $text, $m) === 1) {
            $set('reference', strtoupper($m[1]), 'medium');
        }

        // dates
        $set('bill_date', $this->labelledDate($text, '(?:invoice\s+)?date(?:\s+of\s+issue)?|tax\s+point'), 'medium');
        $set('due_date', $this->labelledDate($text, 'due\s+date|payment\s+due|due\s+by|pay\s+by'), 'medium');

        // currency
        if (preg_match('/\b(ZAR|USD|EUR|GBP|BWP|NAD|MZN|ZMW|AUD)\b/', $text, $m) === 1) {
            $set('currency', $m[1], 'medium');
        } elseif (preg_match('/\bR\s?\d/', $text) === 1) {
            $set('currency', 'ZAR', 'low');
        }

        // amounts
        $total = $this->labelledAmount($text, '(?:total\s+(?:due|amount|payable|incl(?:uding|usive|\.)?\s*(?:of\s+)?vat)|amount\s+due|balance\s+due|invoice\s+total|grand\s+total|total(?!\s*(?:excl|ex\.|before|vat)))');
        $tax = $this->labelledAmount($text, '(?:vat(?:\s*(?:@|at)?\s*15(?:[.,]0+)?\s*%)?(?:\s+amount)?|tax(?:\s+amount)?|total\s+vat)');
        $subtotal = $this->labelledAmount($text, '(?:sub\s*-?\s*total|total\s+(?:excl(?:uding|usive|\.)?\s*(?:of\s+)?vat)|net\s+amount|amount\s+excl(?:uding|usive|\.)?\s*vat)');

        if ($total !== null) {
            $set('total_cents', $total, 'medium');
        }
        if ($tax !== null && $tax !== $total) {
            $set('tax_cents', $tax, 'medium');
        }
        if ($subtotal !== null && $subtotal !== $total) {
            $set('subtotal_cents', $subtotal, 'medium');
        }

        // the figures vouch for each other, or they do not
        if ($total !== null && $tax !== null && $subtotal !== null) {
            if ($subtotal + $tax === $total) {
                foreach (['total_cents', 'tax_cents', 'subtotal_cents'] as $f) {
                    $confidence[$f] = 'high';
                }
            } else {
                $warnings[] = 'The subtotal and VAT do not add up to the total on this document. Check the three figures.';
                foreach (['total_cents', 'tax_cents', 'subtotal_cents'] as $f) {
                    $confidence[$f] = 'low';
                }
            }
        } elseif ($total !== null && $tax !== null && $subtotal === null) {
            $set('subtotal_cents', $total - $tax, 'medium');
            $warnings[] = 'The subtotal was worked out as total minus VAT; it was not printed on the document.';
        }

        if (isset($fields['tax_cents'], $fields['subtotal_cents']) && $fields['subtotal_cents'] > 0) {
            $expected = (int) round($fields['subtotal_cents'] * 15 / 100);
            if (abs($expected - $fields['tax_cents']) > 1 && $fields['tax_cents'] > 0) {
                $warnings[] = 'The VAT is not 15% of the subtotal. That can be right (zero-rated or mixed items); check it.';
            }
        }

        // supplier name: the first line that is not a document label
        foreach (array_slice($lines, 0, 6) as $line) {
            if (preg_match('/^(tax\s+invoice|invoice|credit\s+note|statement|page\s+\d|vat\b|date\b)/i', $line) !== 1 && mb_strlen($line) >= 3 && mb_strlen($line) <= 80 && preg_match('/\d{4,}/', $line) !== 1) {
                $set('supplier_name', $line, 'low');
                break;
            }
        }

        if ($fields === []) {
            $warnings[] = 'Nothing could be recognised on this document. Enter it by hand.';
        }

        return new ExtractionResult($this->name(), $fields, $confidence, $warnings, $fields === [], mb_substr(implode("\n", array_slice($lines, 0, 40)), 0, 2000));
    }

    private function labelledDate(string $text, string $label): ?string
    {
        if (preg_match('/(?:'.$label.')\s*[:\-]?\s*(\d{4}-\d{2}-\d{2}|\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{2,4}|\d{1,2}\s+[A-Za-z]{3,9}\.?,?\s+\d{4})/i', $text, $m) !== 1) {
            return null;
        }

        return self::normaliseDate($m[1]);
    }

    public static function normaliseDate(string $raw): ?string
    {
        $raw = trim($raw);

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
                return CarbonImmutable::createFromFormat('!Y-m-d', $raw)->toDateString();
            }
            if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})$/', $raw, $m) === 1) {
                $year = (int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3];

                // South African documents are day first
                return CarbonImmutable::createFromFormat('!Y-n-j', "{$year}-".(int) $m[2].'-'.(int) $m[1])->toDateString();
            }

            return CarbonImmutable::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function labelledAmount(string $text, string $label): ?int
    {
        // the LAST labelled amount wins for totals (a document total comes after its line items)
        if (preg_match_all('/(?:'.$label.')\s*(?:\(?[^\n\d]{0,25}\)?)?\s*[:\-]?\s*(?:R|ZAR|USD|EUR|GBP)?\s*(-?\d[\d ,.]*\d)/i', $text, $all) < 1) {
            return null;
        }

        for ($i = count($all[1]) - 1; $i >= 0; $i--) {
            $cents = self::parseAmount($all[1][$i]);
            if ($cents !== null) {
                return $cents;
            }
        }

        return null;
    }

    /** "1 234,56", "1,234.56", "1234.5" -> cents, or null when the string is not an unambiguous amount. */
    public static function parseAmount(string $raw): ?int
    {
        $s = trim(str_replace("\u{00A0}", ' ', $raw));
        $s = preg_replace('/\s+/', '', $s) ?? '';

        if (preg_match('/^-?\d{1,3}(,\d{3})*\.\d{1,2}$/', $s) === 1 || preg_match('/^-?\d+\.\d{1,2}$/', $s) === 1) {
            $s = str_replace(',', '', $s);
        } elseif (preg_match('/^-?\d{1,3}(\.\d{3})*,\d{1,2}$/', $s) === 1 || preg_match('/^-?\d+,\d{1,2}$/', $s) === 1) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } elseif (preg_match('/^-?\d+$/', $s) === 1) {
            return null; // a bare whole number is more likely a quantity or a code than money
        } else {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', ltrim($s, '-')), 2, '');
        $cents = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');

        return str_starts_with($s, '-') ? -$cents : $cents;
    }
}
