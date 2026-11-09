<?php

namespace App\Services\Bank\Parsing;

use App\Support\Money;

/**
 * A generic OFX / QFX reader (versions 1.x SGML and 2.x XML) for bank statements: the transactions in
 * <STMTTRN> blocks, the ledger balance, the currency and the period. It reads what the file says and nothing else.
 * It does not implement the OFX protocol (no connection to anything, no sign-on): it only reads a file the person
 * downloaded.
 *
 * Dates are year-first and amounts use a dot (comma accepted when it is the only mark), so both are stated as hints.
 */
final class OfxStatementParser
{
    public function parse(string $contents): RawStatement
    {
        $problems = [];

        if (preg_match('/CHARSET:\s*1252/i', substr($contents, 0, 600)) === 1 || ! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }

        $start = stripos($contents, '<OFX');
        if ($start === false) {
            return new RawStatement('ofx', [], [], ['This is not an OFX file: no <OFX> section was found.']);
        }
        $body = substr($contents, $start);

        $hints = ['date_format' => 'ymd', 'amount_locale' => 'en'];

        if (preg_match('/<CURDEF>\s*([A-Za-z]{3})/i', $body, $m) === 1) {
            $hints['currency'] = strtoupper($m[1]);
        }

        if (preg_match('/<LEDGERBAL>.*?<BALAMT>\s*([-+0-9.,]+)/is', $body, $m) === 1) {
            $cents = $this->cents($m[1]);
            if ($cents !== null) {
                $hints['closing_cents'] = $cents;
            }
        }

        if (preg_match('/<DTSTART>\s*(\d{8})/i', $body, $m) === 1) {
            $hints['date_from'] = $this->isoDate($m[1]);
        }
        if (preg_match('/<DTEND>\s*(\d{8})/i', $body, $m) === 1) {
            $hints['date_to'] = $this->isoDate($m[1]);
        }

        $rows = [];
        $line = 0;

        if (preg_match_all('/<STMTTRN>(.*?)(?=<\/STMTTRN>|<STMTTRN>|<\/BANKTRANLIST>|<\/CCSTMTRS>|<\/STMTRS>|$)/is', $body, $blocks) > 0) {
            foreach ($blocks[1] as $block) {
                $line++;
                $row = RawStatement::blankRow($line);

                $posted = $this->tag($block, 'DTPOSTED');
                $row['raw_date'] = $posted !== null && preg_match('/^(\d{8})/', $posted, $d) === 1 ? $this->isoDate($d[1]) : null;
                $row['raw_amount'] = $this->tag($block, 'TRNAMT');
                $name = $this->tag($block, 'NAME');
                $memo = $this->tag($block, 'MEMO');
                $description = trim(($name ?? '').(($memo !== null && $memo !== $name) ? ' '.$memo : ''));
                $row['description'] = $description === '' ? null : mb_substr($description, 0, 255);
                $row['reference'] = $this->tag($block, 'CHECKNUM') ?? $this->tag($block, 'REFNUM');
                $row['external_id'] = $this->tag($block, 'FITID');
                $rows[] = $row;
            }
        }

        if ($rows === []) {
            $problems[] = 'No transactions were found in this OFX file.';
        }

        return new RawStatement('ofx', $rows, $hints, $problems);
    }

    private function tag(string $block, string $name): ?string
    {
        if (preg_match('/<'.$name.'>\s*([^<\r\n]*)/i', $block, $m) !== 1) {
            return null;
        }

        $value = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1));

        return $value === '' ? null : $value;
    }

    private function isoDate(string $yyyymmdd): ?string
    {
        $y = (int) substr($yyyymmdd, 0, 4);
        $m = (int) substr($yyyymmdd, 4, 2);
        $d = (int) substr($yyyymmdd, 6, 2);

        return checkdate($m, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
    }

    private function cents(string $raw): ?int
    {
        $locale = (str_contains($raw, ',') && ! str_contains($raw, '.')) ? 'eu' : 'en';

        try {
            return Money::parseLocalized($raw, $locale);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
