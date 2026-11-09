<?php

namespace App\Services\Reporting\Export;

use App\Services\Reporting\ReportResult;
use RuntimeException;
use ZipArchive;

/**
 * A minimal, valid .xlsx writer: one sheet, inline strings (so there is no shared-string table to get wrong), numbers as
 * numbers with a money format, a bold header row. Text is escaped for XML and never written as a formula. The result is
 * checked by the tests by unzipping it and parsing every part as XML.
 */
final class XlsxWriter
{
    public static function available(): bool
    {
        return class_exists(ZipArchive::class);
    }

    /** Write the report to $path (replacing it atomically). */
    public static function write(ReportResult $report, string $path): int
    {
        if (! self::available()) {
            throw new RuntimeException('The PHP zip extension is not installed, so a spreadsheet cannot be written. Use CSV.');
        }

        // The sheet is written to a temporary file as the rows come, then added to the archive: a spreadsheet of hundreds of
        // thousands of rows costs the memory of one row, not of the whole sheet (and not of the copies a string would make).
        $sheetFile = $path.'.sheet.part';
        $handle = fopen($sheetFile, 'w');
        fwrite($handle, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');
        $count = self::rows($report, $handle);
        fwrite($handle, '</sheetData></worksheet>');
        fclose($handle);

        $tmp = $path.'.part';
        @unlink($tmp);
        $zip = new ZipArchive;
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the spreadsheet file.');
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.self::esc(self::sheetName($report->title)).'" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
        $zip->addFile($sheetFile, 'xl/worksheets/sheet1.xml');

        if (! $zip->close()) {
            @unlink($tmp);
            @unlink($sheetFile);
            throw new RuntimeException('Could not finish the spreadsheet file.');
        }
        @unlink($sheetFile);

        rename($tmp, $path);

        return $count;
    }

    /** Write every row to $handle; returns how many rows the sheet has. @param  resource  $handle */
    private static function rows(ReportResult $report, $handle): int
    {
        $n = 0;
        $emit = function (array $cells, bool $bold = false) use ($handle, &$n) {
            $n++;
            $xml = '<row r="'.$n.'">';
            foreach (array_values($cells) as $i => $cell) {
                if ($cell === null || $cell === '') {
                    continue;
                }
                $ref = self::column($i).$n;
                if (is_array($cell)) { // ['number' => '123.45'] a money/percentage figure
                    $xml .= '<c r="'.$ref.'" s="2"><v>'.self::esc($cell['number']).'</v></c>';
                } else {
                    $xml .= '<c r="'.$ref.'" t="inlineStr"'.($bold ? ' s="1"' : '').'><is><t xml:space="preserve">'.self::esc((string) $cell).'</t></is></c>';
                }
            }
            fwrite($handle, $xml.'</row>');
        };

        $h = $report->header;
        $emit([$report->title], true);
        foreach ([['Ledger', $h['ledger'] ?? ''], ['Period', $h['period'] ?? ''], ['Compared with', $h['comparison'] ?? ''], ['Currency', $h['currency'] ?? ''], ['Basis', $h['basis'] ?? ''], ['Generated at', $h['generated_at'] ?? '']] as $pair) {
            if ($pair[1] !== '') {
                $emit($pair);
            }
        }
        foreach ($h['rule_versions'] ?? [] as $name => $version) {
            $emit([$name, $version]);
        }
        foreach ($h['assumptions'] ?? [] as $a) {
            $emit(['Assumption', $a]);
        }
        $emit([]);
        $emit(array_map(fn ($c) => $c['label'], $report->columns), true);

        foreach ($report->eachRow() as $row) {
            if ($row['type'] === 'section') {
                $emit([$row['label']], true);

                continue;
            }
            $cells = [];
            foreach ($report->columns as $i => $col) {
                if ($i === 0 && $col['key'] === 'label') {
                    $cells[] = str_repeat('  ', max(0, ($row['depth'] ?? 1) - 1)).$row['label'];

                    continue;
                }
                $v = $row['cells'][$col['key']] ?? null;
                $cells[] = in_array($col['type'], ['money', 'pct'], true) && $v !== null ? ['number' => ReportCsv::cell($col['type'], $v)] : ReportCsv::cell($col['type'], $v);
            }
            $emit($cells, in_array($row['type'], ['subtotal', 'total'], true));
        }

        if ($report->checks !== []) {
            $emit([]);
            foreach ($report->checks as $c) {
                $emit(['Check', $c['label'], $c['ok'] ? 'OK' : 'DOES NOT AGREE', $c['detail']]);
            }
        }
        foreach ($report->notes as $note) {
            $emit(['Note', $note]);
        }

        return $n;
    }

    private static function column(int $index): string
    {
        $name = '';
        for ($i = $index; $i >= 0; $i = intdiv($i, 26) - 1) {
            $name = chr(65 + $i % 26).$name;
        }

        return $name;
    }

    private static function esc(string $text): string
    {
        $text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $text) ?? '';

        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function sheetName(string $title): string
    {
        return mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $title) ?: 'Report', 0, 31);
    }
}
