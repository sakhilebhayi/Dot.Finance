<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use App\Models\Shared\Attachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a stored file. Reached only through a signed URL (the route's `signed` middleware), and then
 * only if the signed-in person may view the record the file belongs to (AttachmentPolicy). The binding is
 * ledger-scoped, so another ledger's file is a 404.
 */
class AttachmentController extends Controller
{
    public function show(Request $request, Attachment $attachment): StreamedResponse
    {
        $this->authorize('view', $attachment);

        $disk = Storage::disk($attachment->disk);
        abort_unless($disk->exists($attachment->path), 404);

        $inline = $request->boolean('inline') && $attachment->isInline();

        return $disk->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'ETag' => '"'.$attachment->sha256.'"',
        ], $inline ? 'inline' : 'attachment');
    }
}
