<?php

namespace App\Services\Purchases\Extraction;

use App\Models\Shared\Attachment;

/**
 * Reads a supplier invoice or a receipt into a DRAFT. Whatever an extractor returns is a suggestion: a person reviews and corrects it,
 * and nothing is created from it without that. An extractor never posts, never creates a bill, never trusts its own output.
 */
interface Extractor
{
    /** A short stable name stored with every draft ("rules", "model"). */
    public function name(): string;

    /** Can this engine read the file at all (its type, its configuration)? */
    public function supports(Attachment $attachment): bool;

    /**
     * @param  string  $bytes  the file's content
     *
     * @throws \RuntimeException when the file cannot be read; the caller records the failure and the person enters the document by hand
     */
    public function extract(Attachment $attachment, string $bytes): ExtractionResult;
}
