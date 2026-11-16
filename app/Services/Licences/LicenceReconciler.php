<?php

namespace App\Services\Licences;

use App\Models\Licences\LicenceBatch;
use App\Models\Licences\LicenceCreditNote;
use App\Models\Licences\LicenceOutboxEvent;
use App\Models\Licences\LicencePayment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Read-only comparison of Finance's licence records with what InfoDot holds. It changes nothing, anywhere.
 *
 * Finance-side checks (always): a payment whose confirmation never reached InfoDot, a confirmation that is dead or rejected,
 * money that fits no batch, a batch flagged for review, a paid batch with no invoice or a failed one, a checkout abandoned for a day.
 * InfoDot-side check (only when LICENCE_RECONCILE_INFODOT_URL is set): per company, each batch's status, quantity and period end
 * against InfoDot's answer. The shape of that answer is an ASSUMPTION to agree with the InfoDot build:
 * {"batches":[{"batch_uuid","status","quantity","period_end"}]}, signed like every Finance read (X-InfoDot headers, empty body).
 */
final class LicenceReconciler
{
    /** @return list<array{type: string, batch_uuid: ?string, detail: string}> */
    public function run(?string $teamUuid = null): array
    {
        $out = [];
        $batches = LicenceBatch::query()->when($teamUuid, fn ($q) => $q->where('team_uuid', strtolower($teamUuid)))->orderBy('id')->get();
        $add = function (string $type, ?string $batch, string $detail) use (&$out) {
            $out[] = ['type' => $type, 'batch_uuid' => $batch, 'detail' => $detail];
        };

        foreach ($batches as $b) {
            if ($b->needs_review) {
                $add('needs_review', $b->batch_uuid, 'Flagged for a person: an amount, a state or more than one batch did not fit.');
            }
            if ($b->status === LicenceBatch::AWAITING_PAYMENT && $b->created_at->lt(now()->subDay())) {
                $add('abandoned_checkout', $b->batch_uuid, 'Checkout started over a day ago and never paid (InfoDot holds nothing for it).');
            }
        }

        $ids = $batches->pluck('id');
        $payments = LicencePayment::query()->where(fn ($q) => $q->whereIn('licence_batch_id', $ids)->when($teamUuid === null, fn ($w) => $w->orWhereNull('licence_batch_id')))->orderBy('id')->get();
        $byBatch = $batches->keyBy('id');

        foreach ($payments as $p) {
            $batch = $byBatch->get($p->licence_batch_id);

            if ($p->status === 'unmatched') {
                $add('unmatched_payment', $batch?->batch_uuid, 'Paystack '.$p->paystack_reference.' ('.$p->amount_minor.' '.$p->currency.') was taken and could not be applied.');

                continue;
            }

            $type = $p->kind === 'initial' ? 'paid' : 'renewed';
            $event = LicenceOutboxEvent::query()->where('event_id', 'lic:'.$batch?->batch_uuid.':'.$type.':'.$p->paystack_reference)->first();

            if ($event === null) {
                $add('confirmation_missing', $batch?->batch_uuid, 'No '.$type.' confirmation exists for payment '.$p->paystack_reference.'.');
            } elseif ($event->status !== LicenceOutboxEvent::SENT) {
                $add('confirmation_undelivered', $batch->batch_uuid, ucfirst($type).' confirmation for '.$p->paystack_reference.' is '.$event->status.' after '.$event->attempts.' attempt(s): '.($event->last_error ?? 'not yet tried').'.');
            }

            if ($p->invoice_status !== 'issued') {
                $add('invoice_missing', $batch?->batch_uuid, 'Payment '.$p->paystack_reference.' has no invoice ('.($p->invoice_status ?? 'not attempted').($p->invoice_error ? ': '.$p->invoice_error : '').').');
            }
        }

        foreach (LicenceOutboxEvent::query()->whereIn('status', [LicenceOutboxEvent::DEAD, LicenceOutboxEvent::REJECTED])->when($teamUuid, fn ($q) => $q->whereIn('batch_uuid', $batches->pluck('batch_uuid')))->get() as $e) {
            $add('confirmation_stuck', $e->batch_uuid, $e->type.' '.$e->event_id.' is '.$e->status.' ('.($e->last_error ?? '').').');
        }

        foreach (LicenceCreditNote::query()->where('status', '<>', 'issued')->get() as $n) {
            $add('credit_note_missing', null, 'Refund '.$n->refund_id.' has no credit note: '.($n->error ?? $n->status).'.');
        }

        return [...$out, ...$this->againstInfoDot($batches)];
    }

    /**
     * @param  Collection<int, LicenceBatch>  $batches
     * @return list<array{type: string, batch_uuid: ?string, detail: string}>
     */
    private function againstInfoDot($batches): array
    {
        $template = (string) config('licences.reconcile.infodot_url');
        $secret = (string) config('licences.push.secret');

        if ($template === '' || $secret === '') {
            return [];
        }

        $out = [];

        foreach ($batches->groupBy('team_uuid') as $team => $mine) {
            $theirs = $this->fetch(str_replace('{team_uuid}', (string) $team, $template), $secret);

            if ($theirs === null) {
                $out[] = ['type' => 'infodot_unreachable', 'batch_uuid' => null, 'detail' => 'InfoDot gave no usable answer for company '.$team.'.'];

                continue;
            }

            $held = collect($theirs)->keyBy('batch_uuid');

            foreach ($mine as $b) {
                $should = in_array($b->status, [LicenceBatch::ACTIVE, LicenceBatch::PAST_DUE, LicenceBatch::CANCELLED], true) && $b->first_paid_at !== null && $b->period_end?->isFuture();
                $row = $held->get($b->batch_uuid);

                if ($should && $row === null) {
                    $out[] = ['type' => 'infodot_missing_batch', 'batch_uuid' => $b->batch_uuid, 'detail' => 'Finance has it '.$b->status.' to '.$b->period_end?->toDateString().'; InfoDot does not list it.'];

                    continue;
                }

                if ($row === null) {
                    continue;
                }

                if ((int) ($row['quantity'] ?? -1) !== $b->quantity) {
                    $out[] = ['type' => 'infodot_quantity_differs', 'batch_uuid' => $b->batch_uuid, 'detail' => 'Finance '.$b->quantity.', InfoDot '.($row['quantity'] ?? '?').'.'];
                }

                $theirEnd = isset($row['period_end']) ? strtotime((string) $row['period_end']) : false;
                if ($b->period_end !== null && ($theirEnd === false || abs($theirEnd - $b->period_end->getTimestamp()) > (int) config('licences.reconcile.period_tolerance_seconds', 86400))) {
                    $out[] = ['type' => 'infodot_period_differs', 'batch_uuid' => $b->batch_uuid, 'detail' => 'Finance period ends '.$b->period_end->toDateString().', InfoDot '.($row['period_end'] ?? 'none').'.'];
                }

                $theirActive = in_array($row['status'] ?? '', ['active', 'grace', 'past_due', 'cancelled', 'ending'], true);
                if ($should !== $theirActive && ! in_array($b->status, [LicenceBatch::CANCELLED], true)) {
                    $out[] = ['type' => 'infodot_status_differs', 'batch_uuid' => $b->batch_uuid, 'detail' => 'Finance '.$b->status.', InfoDot '.($row['status'] ?? '?').'.'];
                }
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>>|null */
    private function fetch(string $url, string $secret): ?array
    {
        $ts = time();

        try {
            $res = Http::timeout((int) config('licences.reconcile.timeout', 8))->acceptJson()
                ->withHeaders(['X-InfoDot-Timestamp' => (string) $ts, 'X-InfoDot-Signature' => LicenceSignature::sign($secret, $ts, '')])->get($url);
        } catch (Throwable) {
            return null;
        }

        if ($res->status() === 404) {
            return [];
        }

        $batches = $res->json('batches');

        return $res->successful() && is_array($batches) ? array_values(array_filter($batches, 'is_array')) : null;
    }
}
