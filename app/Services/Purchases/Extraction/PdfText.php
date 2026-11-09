<?php

namespace App\Services\Purchases\Extraction;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The text of a PDF, without any PDF library.
 *
 *  1. `pdftotext` (poppler) when it is installed: the best reader, keeps the layout.
 *  2. Otherwise a small reader of our own: it inflates the content streams and collects the text-showing operators. It handles the
 *     ordinary text PDFs that accounting and billing software produce (simple fonts, literal strings). It does NOT handle scans (images),
 *     encrypted files, or fonts with custom encodings; for those it returns an empty string and the person types the document in.
 */
final class PdfText
{
    /** One inflated stream may not grow past this (a "zip bomb" PDF packs gigabytes into a few kilobytes). */
    private const MAX_STREAM_BYTES = 4 * 1024 * 1024;

    /** All the content read out of one file may not grow past this, whatever the number of streams. */
    private const MAX_TOTAL_BYTES = 16 * 1024 * 1024;

    /** At most this many streams are looked at: a file made of a million empty streams costs the same as a normal one. */
    private const MAX_STREAMS = 2000;

    public function read(string $bytes): string
    {
        $viaTool = $this->viaPdftotext($bytes);

        return $viaTool ?? $this->viaParser($bytes);
    }

    public function viaPdftotext(string $bytes): ?string
    {
        $binary = (new ExecutableFinder)->find('pdftotext');
        if ($binary === null) {
            return null;
        }

        $path = tempnam(sys_get_temp_dir(), 'fxpdf');
        if ($path === false) {
            return null;
        }

        try {
            file_put_contents($path, $bytes);
            $process = new Process([$binary, '-layout', '-enc', 'UTF-8', $path, '-'], null, null, null, 20);
            $process->run();

            return $process->isSuccessful() ? $process->getOutput() : null;
        } catch (\Throwable) {
            return null;
        } finally {
            @unlink($path);
        }
    }

    public function viaParser(string $bytes): string
    {
        if (! str_starts_with($bytes, '%PDF') || stripos($bytes, '/Encrypt') !== false) {
            return '';
        }

        $text = '';
        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $bytes, $streams) === false) {
            return '';
        }

        $budget = self::MAX_TOTAL_BYTES;

        foreach (array_slice($streams[1], 0, self::MAX_STREAMS) as $raw) {
            if ($budget <= 0) {
                break;
            }
            $content = $this->inflate($raw);
            if ($content === null || ! str_contains($content, 'BT')) {
                continue;
            }
            $budget -= strlen($content);
            $text .= $this->textOperators($content)."\n";
        }

        return trim(preg_replace("/[ \t]+/", ' ', $text) ?? '');
    }

    private function inflate(string $raw): ?string
    {
        foreach (['gzuncompress', 'gzinflate'] as $fn) {
            // The second argument caps the output, so a bomb is cut off at the limit instead of filling memory.
            $out = @$fn($fn === 'gzinflate' ? substr($raw, 2) : $raw, self::MAX_STREAM_BYTES);
            if (is_string($out) && $out !== '') {
                return $out;
            }
        }

        // an uncompressed stream is already text
        return preg_match('/^[\x09\x0A\x0D\x20-\x7E]*$/', substr($raw, 0, 200)) === 1 ? $raw : null;
    }

    /** Walk a content stream and collect the strings shown by Tj, TJ, ' and ", breaking lines on text-positioning operators. */
    private function textOperators(string $s): string
    {
        $out = '';
        $length = strlen($s);
        $i = 0;
        $pending = '';

        while ($i < $length) {
            $c = $s[$i];

            if ($c === '(') {
                [$string, $i] = $this->literal($s, $i);
                $pending .= $string;

                continue;
            }

            if ($c === '[') {
                $i++;
                while ($i < $length && $s[$i] !== ']') {
                    if ($s[$i] === '(') {
                        [$string, $i] = $this->literal($s, $i);
                        $pending .= $string;
                    } elseif (preg_match('/\G-?\d+(?:\.\d+)?/', $s, $m, 0, $i) === 1) {
                        $pending .= (float) $m[0] < -200 ? ' ' : '';
                        $i += strlen($m[0]);
                    } else {
                        $i++;
                    }
                }
                $i++;

                continue;
            }

            if (ctype_alpha($c) || $c === '\'' || $c === '"' || $c === '*') {
                preg_match('/\G[A-Za-z\'"*]+/', $s, $m, 0, $i);
                $op = $m[0];
                $i += strlen($op);

                if (in_array($op, ['Tj', 'TJ', '\'', '"'], true)) {
                    $out .= $pending;
                    $pending = '';
                } elseif (in_array($op, ['T*', 'Td', 'TD', 'Tm', 'ET'], true)) {
                    $out .= $pending."\n";
                    $pending = '';
                } else {
                    $pending = '';
                }

                continue;
            }

            $i++;
        }

        return $out.$pending;
    }

    /** @return array{0: string, 1: int} the decoded string and the index after its closing parenthesis */
    private function literal(string $s, int $i): array
    {
        $depth = 0;
        $out = '';
        $length = strlen($s);

        for (; $i < $length; $i++) {
            $c = $s[$i];

            if ($c === '\\' && $i + 1 < $length) {
                $n = $s[++$i];
                if (ctype_digit($n)) {
                    $oct = $n;
                    while (strlen($oct) < 3 && $i + 1 < $length && ctype_digit($s[$i + 1]) && $s[$i + 1] < '8') {
                        $oct .= $s[++$i];
                    }
                    $out .= chr(octdec($oct) & 0xFF);
                } else {
                    $out .= match ($n) {
                        'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C", default => $n,
                    };
                }

                continue;
            }

            if ($c === '(') {
                if ($depth++ > 0) {
                    $out .= $c;
                }

                continue;
            }
            if ($c === ')') {
                if (--$depth === 0) {
                    return [$out, $i + 1];
                }
                $out .= $c;

                continue;
            }

            $out .= $c;
        }

        return [$out, $i];
    }
}
