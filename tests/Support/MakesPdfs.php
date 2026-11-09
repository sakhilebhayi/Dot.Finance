<?php

namespace Tests\Support;

/** Builds the smallest valid PDFs that carry text, so the reader can be tested without a PDF library. */
final class MakesPdfs
{
    /** @param list<string> $lines */
    public static function text(array $lines, bool $compressed = false): string
    {
        $content = "BT\n/F1 12 Tf\n72 780 Td\n14 TL\n";
        foreach ($lines as $i => $line) {
            $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
            $content .= ($i === 0 ? '' : "T*\n")."({$escaped}) Tj\n";
        }
        $content .= "ET\n";

        $stream = $compressed ? gzcompress($content) : $content;
        $filter = $compressed ? ' /Filter /FlateDecode' : '';

        return "%PDF-1.4\n"
            ."1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
            ."2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
            ."3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>\nendobj\n"
            .'4 0 obj\n<< /Length '.strlen($stream)."{$filter} >>\nstream\n".$stream."\nendstream\nendobj\n"
            ."5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n"
            ."trailer\n<< /Root 1 0 R /Size 6 >>\n%%EOF\n";
    }

    /** The lines of a typical South African supplier tax invoice. @return list<string> */
    public static function invoiceLines(string $reference = 'INV-20457', string $total = '1 150.00', string $tax = '150.00', string $subtotal = '1 000.00'): array
    {
        return [
            'Acme Supplies (Pty) Ltd', '12 Long Street, Cape Town', 'TAX INVOICE', 'VAT Reg No: 4123456789', "Invoice No: {$reference}", 'Date: 05/11/2026', 'Due date: 05/12/2026',
            'Coffee bags 100 x R10.00', "Subtotal R {$subtotal}", "VAT 15% R {$tax}", "Total (incl. VAT) R {$total}",
        ];
    }
}
