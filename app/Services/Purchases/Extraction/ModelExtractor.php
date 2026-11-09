<?php

namespace App\Services\Purchases\Extraction;

use App\Models\Shared\Attachment;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads an image, a scan or a PDF with a language model. OFF by default (config purchases.extraction.model_enabled plus a key).
 *
 * What it is allowed to do is narrow, on purpose:
 *   - it is asked for one JSON object with a fixed set of fields and nothing else;
 *   - the answer is validated field by field (dates must be real dates, amounts whole numbers of cents, the currency three letters, the
 *     document type one of the known kinds); a field that fails is dropped with a warning, an answer that is not JSON is a failure;
 *   - every value it returns is "medium" confidence at best, never "high", and the result is labelled as model-read in the draft;
 *   - the output is a DRAFT. It never posts, never creates a bill, and the person must review it.
 *
 * Privacy: enabling this sends the document to the configured endpoint. Do not enable it for documents that must not leave the ledger.
 */
final class ModelExtractor implements Extractor
{
    private const SYSTEM = 'You read one supplier invoice or receipt and return ONLY a JSON object, no prose, with exactly these keys: '
        .'supplier_name (string|null), supplier_vat_number (string of digits|null), reference (invoice number string|null), bill_date (YYYY-MM-DD|null), due_date (YYYY-MM-DD|null), '
        .'currency (3-letter ISO code|null), subtotal_cents (integer|null), tax_cents (integer|null), total_cents (integer|null), document_type ("tax_invoice"|"abridged_invoice"|"credit_note"|"none"|null). '
        .'Amounts are whole numbers of the minor unit (cents). Use null for anything you cannot read. Never guess an amount.';

    public function name(): string
    {
        return 'model';
    }

    public function supports(Attachment $attachment): bool
    {
        return $this->enabled() && (str_starts_with($attachment->mime, 'image/') || $attachment->mime === 'application/pdf');
    }

    public function enabled(): bool
    {
        return (bool) config('purchases.extraction.model_enabled') && filled(config('purchases.extraction.model_api_key'));
    }

    public function extract(Attachment $attachment, string $bytes): ExtractionResult
    {
        if (! $this->enabled()) {
            throw new RuntimeException('The model reader is switched off.');
        }

        $block = str_starts_with($attachment->mime, 'image/')
            ? ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $attachment->mime, 'data' => base64_encode($bytes)]]
            : ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($bytes)]];

        $response = Http::timeout((int) config('purchases.extraction.timeout_seconds', 30))
            ->withHeaders(['x-api-key' => (string) config('purchases.extraction.model_api_key'), 'anthropic-version' => '2023-06-01'])
            ->acceptJson()
            ->post((string) config('purchases.extraction.model_endpoint'), [
                'model' => (string) config('purchases.extraction.model_name'),
                'max_tokens' => 800,
                'system' => self::SYSTEM,
                'messages' => [['role' => 'user', 'content' => [$block, ['type' => 'text', 'text' => 'Read this document.']]]],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('The model reader did not answer (HTTP '.$response->status().').');
        }

        $text = (string) data_get($response->json(), 'content.0.text', '');

        return $this->validated($text);
    }

    /** Public so the validation can be tested on its own. */
    public function validated(string $answer): ExtractionResult
    {
        $answer = trim($answer);
        if (preg_match('/\{.*\}/s', $answer, $m) === 1) {
            $answer = $m[0];
        }

        $data = json_decode($answer, true);
        if (! is_array($data)) {
            throw new RuntimeException('The model reader did not return JSON.');
        }

        $fields = [];
        $warnings = [];
        $drop = function (string $key, string $why) use (&$warnings) {
            $warnings[] = "The model's value for {$key} was ignored: {$why}.";
        };

        foreach ($data as $key => $value) {
            if (! in_array($key, ExtractionResult::FIELDS, true)) {
                $warnings[] = "The model returned an unexpected field \"{$key}\"; it was ignored.";

                continue;
            }
            if ($value === null) {
                continue;
            }

            switch ($key) {
                case 'supplier_name':
                case 'reference':
                    is_string($value) && trim($value) !== '' && mb_strlen($value) <= 120 ? $fields[$key] = trim($value) : $drop($key, 'not a short text');
                    break;
                case 'supplier_vat_number':
                    is_string($value) && preg_match('/^\d{10}$/', preg_replace('/\s+/', '', $value)) === 1 ? $fields[$key] = preg_replace('/\s+/', '', $value) : $drop($key, 'not a ten-digit number');
                    break;
                case 'bill_date':
                case 'due_date':
                    is_string($value) && RuleBasedExtractor::normaliseDate($value) === $value && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $fields[$key] = $value : $drop($key, 'not a real date');
                    break;
                case 'currency':
                    is_string($value) && preg_match('/^[A-Z]{3}$/', $value) === 1 ? $fields[$key] = $value : $drop($key, 'not a three-letter currency code');
                    break;
                case 'subtotal_cents':
                case 'tax_cents':
                case 'total_cents':
                    is_int($value) && $value >= 0 && $value < 100_000_000_000 ? $fields[$key] = $value : $drop($key, 'not a whole number of cents');
                    break;
                case 'document_type':
                    is_string($value) && in_array($value, ['tax_invoice', 'abridged_invoice', 'credit_note', 'none'], true) ? $fields[$key] = $value : $drop($key, 'not a known document type');
                    break;
            }
        }

        if (isset($fields['subtotal_cents'], $fields['tax_cents'], $fields['total_cents']) && $fields['total_cents'] !== $fields['subtotal_cents'] + $fields['tax_cents']) {
            $warnings[] = 'The model\'s subtotal and VAT do not add up to its total. Check the three figures.';
        }

        $confidence = array_fill_keys(array_keys($fields), 'medium');

        return new ExtractionResult($this->name(), $fields, $confidence, array_merge(['Read by a language model: check every value against the document.'], $warnings), $fields === []);
    }
}
