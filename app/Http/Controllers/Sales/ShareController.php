<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Sales\SalesDocument;
use App\Models\Sales\SalesShareLink;
use App\Models\Shared\Party;
use App\Services\Sales\DocumentRenderer;
use App\Services\Sales\ShareLinks;
use App\Services\Sales\Statements;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;

/**
 * What a customer sees when they open a link they were sent: one document or one statement, read-only, no sign-in.
 * Unknown, expired and revoked links are all the same plain 404. The route is throttled and sets no cookie.
 */
class ShareController extends Controller
{
    public function show(string $shareToken, ShareLinks $links, DocumentRenderer $renderer, Statements $statements): Response
    {
        [$link, $context] = $this->open($shareToken, $links);

        $html = LedgerContext::run($context, function () use ($link, $renderer, $statements, $context) {
            if ($link->kind === 'document') {
                $document = SalesDocument::query()->forContext($context)->whereKey($link->document_id)->first() ?? abort(404);

                return view('sales.print.public', $renderer->data($document) + ['pdfUrl' => route('sales.share.pdf', ['shareToken' => request()->route('shareToken')])])->render();
            }

            $party = Party::query()->forContext($context)->withTrashed()->findOrFail($link->party_id);
            $statement = $statements->build($context, $party, CarbonImmutable::parse($link->options['from']), CarbonImmutable::parse($link->options['to']));

            return $renderer->statementHtml($statement);
        });

        $links->viewed($link);

        return $this->plain($html, 'text/html; charset=UTF-8');
    }

    public function pdf(string $shareToken, ShareLinks $links, DocumentRenderer $renderer, Statements $statements): Response
    {
        [$link, $context] = $this->open($shareToken, $links);

        $bytes = LedgerContext::run($context, function () use ($link, $renderer, $statements, $context) {
            if ($link->kind === 'document') {
                return $renderer->pdf(SalesDocument::query()->forContext($context)->whereKey($link->document_id)->first() ?? abort(404));
            }
            $party = Party::query()->forContext($context)->withTrashed()->findOrFail($link->party_id);

            return $renderer->statementPdf($statements->build($context, $party, CarbonImmutable::parse($link->options['from']), CarbonImmutable::parse($link->options['to'])));
        });

        return $this->plain($bytes, 'application/pdf');
    }

    /** @return array{0: SalesShareLink, 1: LedgerContext} */
    private function open(string $token, ShareLinks $links): array
    {
        $found = $links->resolve($token) ?? abort(404);

        return [$found['link'], $found['context']];
    }

    private function plain(string $body, string $type): Response
    {
        return response($body, 200, ['Content-Type' => $type, 'Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer']);
    }
}
