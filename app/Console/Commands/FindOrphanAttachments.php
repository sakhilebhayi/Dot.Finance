<?php

namespace App\Console\Commands;

use App\Models\Shared\Attachment;
use App\Support\Ledger\LedgerContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Attachment records are never deleted by the app, but the database removes a person's rows when the person is
 * deleted, which leaves their files behind with no record. This lists those files (dry run, the default) and,
 * only with --delete, removes them. It never touches a file that has a record.
 */
#[Signature('finance:attachments:orphans {--delete : Remove the orphaned files instead of only listing them}')]
#[Description('List (or delete) stored attachment files that no attachment record points at')]
class FindOrphanAttachments extends Command
{
    public function handle(): int
    {
        $disk = Storage::disk(config('shared.attachments.disk'));

        // one ledger at a time, through the sanctioned way to visit every ledger
        $known = [];
        foreach (LedgerContext::owningRowsIn('attachments') as $context) {
            foreach (Attachment::query()->forContext($context)->where('disk', config('shared.attachments.disk'))->pluck('path') as $path) {
                $known[$path] = true;
            }
        }

        $orphans = array_values(array_filter($disk->allFiles('finance-attachments'), fn (string $path) => ! isset($known[$path])));

        if ($orphans === []) {
            $this->info('No orphaned attachment files.');

            return self::SUCCESS;
        }

        foreach ($orphans as $path) {
            $this->line(($this->option('delete') ? 'deleting ' : 'orphan   ').$path);
        }

        if ($this->option('delete')) {
            $disk->delete($orphans);
            $this->info('Deleted '.count($orphans).' orphaned file(s).');
        } else {
            $this->warn(count($orphans).' orphaned file(s). Run again with --delete to remove them.');
        }

        return self::SUCCESS;
    }
}
