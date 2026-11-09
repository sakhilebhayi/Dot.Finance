<?php

namespace App\Services\Purchases\Extraction;

use App\Models\Shared\Attachment;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Chooses the engine for a file and runs it. The rule-based reader comes first for anything it can read (it needs no network); the
 * model reader is used for images and scans only when it is switched on. With neither, a file is simply stored and the person
 * types the document in. A failed engine is reported, never hidden, and never stops the upload.
 */
final class ExtractorManager
{
    public function __construct(private readonly RuleBasedExtractor $rules, private readonly ModelExtractor $model) {}

    /** @return list<Extractor> the engines that can read this file, best first */
    public function enginesFor(Attachment $attachment): array
    {
        $engines = [];
        if ($this->rules->supports($attachment)) {
            $engines[] = $this->rules;
        }
        if ($this->model->supports($attachment)) {
            $engines[] = $this->model;
        }

        return $engines;
    }

    /** True when the model reader is on, for screens that say so. */
    public function modelEnabled(): bool
    {
        return $this->model->enabled();
    }

    /**
     * Run the first engine that can read the file; if it found nothing and the model reader can help, run that too.
     *
     * @return list<ExtractionResult> one per engine that ran (a failure is an empty result with a warning)
     */
    public function run(Attachment $attachment): array
    {
        $bytes = (string) Storage::disk($attachment->disk)->get($attachment->path);
        $results = [];

        foreach ($this->enginesFor($attachment) as $engine) {
            try {
                $results[] = $engine->extract($attachment, $bytes);
            } catch (Throwable $e) {
                $results[] = new ExtractionResult($engine->name(), [], [], ['The '.$engine->name().' reader could not read this file: '.$e->getMessage()], true);
            }

            if ($engine === $this->rules && ! end($results)->needsManualEntry) {
                break;
            }
        }

        if ($results === []) {
            $results[] = new ExtractionResult('manual', [], [], ['This kind of file cannot be read automatically. Enter the document by hand; the file is kept as evidence.'], true);
        }

        return $results;
    }
}
