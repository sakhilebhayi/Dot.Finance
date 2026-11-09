<?php

namespace App\Services\Sales;

use App\Models\Concerns\HasLedgerContext;
use App\Models\Sales\SalesDocument;
use App\Models\Sales\SalesDocumentEvent;
use App\Support\FinanceAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/** One place to write the document trail (visible on the document) and the ledger audit log (visible to auditors). */
final class SalesTrail
{
    /** @param  array<string, mixed>  $meta  no free text a person typed, except where the trail is meant to show it (a void reason) */
    public static function event(SalesDocument $document, string $kind, ?int $actorId, array $meta = []): SalesDocumentEvent
    {
        return SalesDocumentEvent::query()->create($document->ledgerOwner() + [
            'document_id' => $document->id, 'kind' => $kind, 'actor_user_id' => $actorId ?? Auth::id(), 'meta' => $meta === [] ? null : $meta,
        ]);
    }

    /** @param  array<string, mixed>|null  $changes */
    public static function audit(Model $subject, string $action, string $type, string $label, ?array $changes = null, ?string $currency = null, ?int $actorId = null): void
    {
        /** @var Model&HasLedgerContext $subject */
        FinanceAudit::record($subject->ledgerOwner(), $action, $type, (int) $subject->getKey(), $label, $changes, null, $currency, $actorId);
    }
}
