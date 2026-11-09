<?php

namespace App\Services\Sales;

use App\Models\Sales\SalesDocument;
use App\Models\Sales\SalesShareLink;
use App\Models\Shared\Party;
use App\Models\User;
use App\Services\Sales\Support\SalesViolation;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Signed customer links. A link is a random token shown once (only its SHA-256 is stored), expires, can be revoked,
 * and opens a read-only copy of one document or one statement with no sign-in. The public route is throttled.
 *
 * Opening a link needs the ledger it belongs to before any scoped model can be read; the hash lookup below is the
 * one deliberate unscoped read (it reads only the owner columns of a row whose secret the caller already holds).
 */
final class ShareLinks
{
    /** @return array{link: SalesShareLink, token: string, url: string} the raw token is returned once and never stored */
    public function forDocument(SalesDocument $document, User $by, ?int $days = null): array
    {
        if (! $document->isSealed()) {
            throw new SalesViolation('Issue the document before sharing it: a draft has no number.', 'sealed');
        }

        return $this->create($document->ledgerContext(), $by, 'document', $document, null, [], $days);
    }

    /** @return array{link: SalesShareLink, token: string, url: string} */
    public function forStatement(LedgerContext $context, Party $party, User $by, string $from, string $to, ?int $days = null): array
    {
        return $this->create($context, $by, 'statement', null, $party, ['from' => $from, 'to' => $to], $days);
    }

    /** Switch a link off. Idempotent. */
    public function revoke(SalesShareLink $link, ?User $by = null): SalesShareLink
    {
        if ($link->revoked_at === null) {
            $link->forceFill(['revoked_at' => now()])->save();
            if ($link->document_id !== null) {
                $document = SalesDocument::query()->forContext($link->ledgerContext())->find($link->document_id);
                $document !== null && SalesTrail::event($document, 'link_revoked', $by?->id);
            }
        }

        return $link;
    }

    /**
     * Find a live link by the token a customer was given. Returns the link and its ledger, or null (unknown, expired
     * or revoked all look the same to the caller).
     *
     * @return array{link: SalesShareLink, context: LedgerContext}|null
     */
    public function resolve(string $token): ?array
    {
        if (strlen($token) < 30 || strlen($token) > 80 || ! ctype_alnum($token)) {
            return null;
        }

        $row = DB::table('sales_share_links')->where('token_hash', hash('sha256', $token))->first(['id', 'user_id', 'team_id', 'expires_at', 'revoked_at']);
        if ($row === null || $row->revoked_at !== null || CarbonImmutable::parse($row->expires_at)->isPast()) {
            return null;
        }

        $context = LedgerContext::fromOwner($row->user_id !== null ? (int) $row->user_id : null, $row->team_id !== null ? (int) $row->team_id : null);
        $link = SalesShareLink::query()->forContext($context)->find($row->id);

        return $link === null ? null : ['link' => $link, 'context' => $context];
    }

    /** Count a view, and note the first time a document was opened. */
    public function viewed(SalesShareLink $link): void
    {
        $link->increment('views');
        $link->forceFill(['last_viewed_at' => now()])->save();

        if ($link->document_id !== null) {
            $document = SalesDocument::query()->forContext($link->ledgerContext())->find($link->document_id);
            if ($document !== null && $document->viewed_at === null) {
                $document->forceFill(['viewed_at' => now()])->save();
                SalesTrail::event($document, 'viewed', null, ['via' => 'link']);
            }
        }
    }

    /** @param  array<string, mixed>  $options */
    private function create(LedgerContext $context, User $by, string $kind, ?SalesDocument $document, ?Party $party, array $options, ?int $days): array
    {
        $days = max(1, min((int) ($days ?? config('sales.share.default_days')), (int) config('sales.share.max_days')));
        $token = Str::random(40);

        $link = SalesShareLink::query()->create($context->ownerAttributes() + [
            'kind' => $kind, 'document_id' => $document?->id, 'party_id' => $party?->id ?? $document?->party_id, 'options' => $options === [] ? null : $options,
            'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays($days), 'created_by' => $by->id,
        ]);

        if ($document !== null) {
            SalesTrail::event($document, 'link_created', $by->id, ['expires' => $link->expires_at->toDateString()]);
        }

        return ['link' => $link, 'token' => $token, 'url' => route('sales.share.show', ['shareToken' => $token])];
    }
}
