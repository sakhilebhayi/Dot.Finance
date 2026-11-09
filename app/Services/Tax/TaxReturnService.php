<?php

namespace App\Services\Tax;

use App\Models\Accounting\JournalEntry;
use App\Models\Tax\TaxDeadline;
use App\Models\Tax\TaxProfile;
use App\Models\Tax\TaxReturn;
use App\Models\Tax\TaxReturnAdjustment;
use App\Models\User;
use App\Services\Accounting\GlSync;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\PostingLine;
use App\Services\Accounting\PostingRequest;
use App\Services\Tax\Dto\ComputedReturn;
use App\Services\Tax\Exceptions\NotReady;
use App\Services\Tax\Exceptions\TaxException;
use App\Support\FinanceAudit;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The life of a VAT201 or EMP201 inside Dot.Finance.
 *
 *   draft  -> prepared   frozen figures, hashed, rule versions named; refused unless every check passes
 *   prepared -> draft    withdrawn by a person, with a reason (before anything was filed)
 *   prepared -> submitted   a PERSON records the SARS reference and date after filing it themselves
 *   submitted -> paid    a PERSON records the payment
 *   submitted/paid -> revision   a new draft that supersedes it
 *
 * Nothing here talks to SARS. "Submitted" means "a person recorded that they submitted it".
 */
final class TaxReturnService
{
    public function __construct(
        private readonly Vat201Builder $vat,
        private readonly Emp201Builder $emp,
        private readonly VatReconciliation $reconciliation,
        private readonly VatReviewQueue $queue,
        private readonly GlSync $sync,
        private readonly JournalPoster $poster,
        private readonly GlTaxLines $lines,
    ) {}

    // ── lookup and creation ─────────────────────────────────────────────

    /** The newest revision for a period, or null. */
    public function current(LedgerContext $ctx, string $kind, CarbonImmutable $start, CarbonImmutable $end): ?TaxReturn
    {
        return TaxReturn::query()->forContext($ctx)->where('kind', $kind)
            ->where('period_start', $start->toDateString())->where('period_end', $end->toDateString())
            ->orderByDesc('revision')->first();
    }

    public function start(LedgerContext $ctx, string $kind, CarbonImmutable $start, CarbonImmutable $end, string $label, ?TaxProfile $profile, ?User $by = null): TaxReturn
    {
        if (! in_array($kind, ['vat201', 'emp201'], true)) {
            throw new TaxException('Unknown return kind.');
        }
        if ($kind === 'vat201' && ($profile === null || ! $profile->vat_registered)) {
            throw new TaxException('This business is not recorded as VAT-registered, so there is no VAT201 to prepare. Complete the tax profile first.');
        }
        if ($end->lt($start)) {
            throw new TaxException('The period ends before it starts.');
        }

        $existing = $this->current($ctx, $kind, $start, $end);
        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($ctx, $kind, $start, $end, $label, $profile, $by) {
            $return = TaxReturn::query()->create($ctx->ownerAttributes() + [
                'kind' => $kind, 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(), 'period_label' => $label,
                'revision' => 1, 'status' => 'draft', 'basis' => $kind === 'vat201' ? ($profile?->vat_basis ?? 'invoice') : null,
                'channel' => $kind === 'vat201' ? ($profile?->vat_filing_channel ?? 'efiling') : null,
            ]);
            FinanceAudit::record($ctx->ownerAttributes(), 'created', 'tax_return', (int) $return->id, $return->kindLabel().' '.$label, ['status' => ['after' => 'draft']], null, null, $by?->id);

            return $return;
        });
    }

    // ── computing ───────────────────────────────────────────────────────

    /** The figures as the books stand now (a draft recomputes every time it is opened). */
    public function compute(LedgerContext $ctx, TaxReturn $return): ComputedReturn
    {
        $from = CarbonImmutable::parse($return->period_start->toDateString());
        $to = CarbonImmutable::parse($return->period_end->toDateString());

        if ($return->kind === 'emp201') {
            return $this->emp->build($ctx, $from, $to);
        }

        $adjustments = $return->activeAdjustments()->get();
        $withAdjustments = $this->vat->build($ctx, $from, $to, $adjustments);

        $booksOnly = $adjustments->isEmpty() ? $withAdjustments : $this->vat->build($ctx, $from, $to, []);
        $withAdjustments->reconciliation = $this->reconciliation->forPeriod($ctx, $from, $to, $booksOnly, $withAdjustments);
        $withAdjustments->reconciliation['review'] = $this->queue->forPeriod($ctx, $from, $to, $booksOnly);

        return $withAdjustments;
    }

    /**
     * Whether the return can be prepared, and exactly what stands in the way.
     *
     * @return array{ready: bool, blockers: list<string>, warnings: list<string>}
     */
    public function checks(LedgerContext $ctx, TaxReturn $return, ComputedReturn $computed, ?TaxProfile $profile, bool $acknowledgePaymentsBasis = false, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $blockers = [];
        $warnings = [];
        $end = CarbonImmutable::parse($return->period_end->toDateString());

        if ($end->gte($today)) {
            $blockers[] = 'The period has not ended yet ('.$end->format('j F Y').'). A final return is prepared after the period closes; the figures below are a preview.';
        }

        if ($return->kind === 'vat201') {
            if ($profile === null || ! $profile->vat_registered) {
                $blockers[] = 'The tax profile does not say the business is VAT-registered.';
            } elseif ($profile->vat_registered_on && $end->lt(CarbonImmutable::parse($profile->vat_registered_on->toDateString()))) {
                $blockers[] = 'The period ended before the VAT registration date in the profile.';
            }
            if (! $this->sync->isEnabled($ctx)) {
                $blockers[] = 'Accounting is not switched on, so there are no journal lines to build the return from.';
            }
            if ($return->basis === 'payments') {
                $msg = 'The profile says the VAT basis is payments, but the figures here are built from the books on the invoice basis (the date of each entry). Add adjustments to bring them to the payments basis.';
                if ($acknowledgePaymentsBasis) {
                    $warnings[] = $msg.' (acknowledged)';
                } else {
                    $blockers[] = $msg.' Tick the acknowledgement once you have done that.';
                }
            }
            foreach ($computed->reconciliation['review'] ?? [] as $issue) {
                if ($issue['severity'] === 'error' && ! $issue['resolved']) {
                    $blockers[] = 'Unresolved: '.$issue['message'];
                } elseif (! $issue['resolved']) {
                    $warnings[] = $issue['message'];
                }
            }
            $rec = $computed->reconciliation;
            if (($rec['ties'] ?? false) !== true) {
                $blockers[] = 'The return does not reconcile to the VAT accounts: '.\App\Support\Money::format(abs((int) ($rec['unexplained_cents'] ?? 0)), 'ZAR').' is unexplained.';
            }
            if ($profile?->vat_category) {
                $warnings = array_merge($warnings, $this->categoryWarnings($ctx, $return, $profile));
            }
            $closedThrough = $this->sync->settings($ctx)?->books_closed_through;
            if ($closedThrough === null || CarbonImmutable::parse($closedThrough->toDateString())->lt($end)) {
                $warnings[] = 'The accounting period is not closed yet: late entries could still change these figures.';
            }
        } else {
            foreach ($computed->issues as $issue) {
                if ($issue['severity'] === 'error') {
                    $blockers[] = $issue['message'];
                } else {
                    $warnings[] = $issue['message'];
                }
            }
            $rec = $computed->reconciliation;
            if (($rec['posted'] ?? false) === false) {
                $warnings[] = 'The payroll journal for this month is not posted, so the payroll liabilities in the books do not include it yet.';
            } elseif (($rec['ties'] ?? false) !== true) {
                $blockers[] = 'The summary does not match the payroll liabilities the payroll journal raised in the books.';
            }
        }

        return ['ready' => $blockers === [], 'blockers' => array_values(array_unique($blockers)), 'warnings' => array_values(array_unique($warnings))];
    }

    // ── adjustments ─────────────────────────────────────────────────────

    public function addAdjustment(LedgerContext $ctx, TaxReturn $return, string $box, string $kind, int $amountCents, string $reason, ?User $by = null, ?int $attachmentId = null): TaxReturnAdjustment
    {
        if ($return->kind !== 'vat201') {
            throw new TaxException('Adjustments are for the VAT201.');
        }
        if (! $return->isDraft()) {
            throw new TaxException('Adjustments can only be added to a draft return. Withdraw the preparation first.');
        }
        if (! in_array($box, Vat201Builder::ADJUSTABLE, true)) {
            throw new TaxException("Field {$box} is calculated and cannot be adjusted directly: adjust the field it is made from.");
        }
        if (! array_key_exists($kind, TaxReturnAdjustment::KINDS)) {
            throw new TaxException('Unknown adjustment kind.');
        }
        if ($amountCents === 0) {
            throw new TaxException('An adjustment of zero changes nothing.');
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new TaxException('Say why: an adjustment needs a reason (at least a few words).');
        }

        return DB::transaction(function () use ($ctx, $return, $box, $kind, $amountCents, $reason, $by, $attachmentId) {
            $adj = TaxReturnAdjustment::query()->create($ctx->ownerAttributes() + [
                'tax_return_id' => $return->id, 'box_code' => $box, 'kind' => $kind, 'amount_cents' => $amountCents,
                'reason' => trim($reason), 'attachment_id' => $attachmentId, 'created_by_user_id' => $by?->id,
            ]);
            FinanceAudit::record($ctx->ownerAttributes(), 'adjusted', 'tax_return', (int) $return->id, $return->kindLabel().' '.$return->period_label, [
                'box' => ['after' => $box], 'kind' => ['after' => $kind], 'amount_cents' => ['after' => $amountCents],
            ], 'adjustment #'.$adj->id, null, $by?->id);

            return $adj;
        });
    }

    public function voidAdjustment(LedgerContext $ctx, TaxReturn $return, TaxReturnAdjustment $adjustment, string $reason, ?User $by = null): TaxReturnAdjustment
    {
        if (! $return->isDraft()) {
            throw new TaxException('Adjustments can only change on a draft return.');
        }
        if ((int) $adjustment->tax_return_id !== (int) $return->id) {
            throw new TaxException('That adjustment belongs to another return.');
        }
        if ($adjustment->isVoided()) {
            return $adjustment;
        }
        if (mb_strlen(trim($reason)) < 3) {
            throw new TaxException('Say why the adjustment is voided.');
        }

        $adjustment->forceFill(['voided_at' => now(), 'voided_by_user_id' => $by?->id, 'void_reason' => trim($reason)])->save();
        FinanceAudit::record($ctx->ownerAttributes(), 'voided', 'tax_return', (int) $return->id, $return->kindLabel().' '.$return->period_label, [
            'box' => ['before' => $adjustment->box_code], 'amount_cents' => ['before' => $adjustment->amount_cents],
        ], 'adjustment #'.$adjustment->id.' voided', null, $by?->id);

        return $adjustment;
    }

    // ── prepare, withdraw, submit, pay, revise ──────────────────────────

    /** @throws NotReady when a check fails */
    public function prepare(LedgerContext $ctx, TaxReturn $return, ?TaxProfile $profile, User $by, bool $acknowledgePaymentsBasis = false, ?CarbonImmutable $today = null): TaxReturn
    {
        return DB::transaction(function () use ($ctx, $return, $profile, $by, $acknowledgePaymentsBasis, $today) {
            $locked = TaxReturn::query()->forContext($ctx)->whereKey($return->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isDraft()) {
                throw new TaxException('Only a draft return can be prepared.');
            }

            $computed = $this->compute($ctx, $locked);
            $checks = $this->checks($ctx, $locked, $computed, $profile, $acknowledgePaymentsBasis, $today);
            if (! $checks['ready']) {
                throw new NotReady($checks['blockers']);
            }

            $figures = $computed->figures() + [
                'adjustments' => $locked->activeAdjustments()->get()->map(fn ($a) => ['id' => $a->id, 'box' => $a->box_code, 'kind' => $a->kind, 'amount_cents' => $a->amount_cents, 'reason' => $a->reason])->all(),
                'notes' => $computed->notes,
            ];
            $hash = $computed->hash();

            $locked->forceFill([
                'status' => 'prepared', 'figures' => $figures, 'figures_hash' => $hash,
                'output_tax_cents' => $computed->outputTaxCents, 'input_tax_cents' => $computed->inputTaxCents, 'payable_cents' => $computed->payableCents,
                'rule_versions' => $computed->ruleVersions, 'reconciliation' => $this->slimReconciliation($computed->reconciliation),
                'checks' => $checks, 'prepared_by_user_id' => $by->id, 'prepared_at' => now(),
            ])->save();

            $this->linkDeadline($ctx, $locked, 'prepared');
            FinanceAudit::record($ctx->ownerAttributes(), 'prepared', 'tax_return', (int) $locked->id, $locked->kindLabel().' '.$locked->period_label, [
                'status' => ['before' => 'draft', 'after' => 'prepared'], 'payable_cents' => ['after' => $computed->payableCents], 'figures_hash' => ['after' => substr($hash, 0, 16)],
            ], 'prepared, not submitted', null, $by->id);

            return $locked;
        });
    }

    public function withdraw(LedgerContext $ctx, TaxReturn $return, string $reason, User $by): TaxReturn
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw new TaxException('Say why the preparation is withdrawn.');
        }

        return DB::transaction(function () use ($ctx, $return, $reason, $by) {
            $locked = TaxReturn::query()->forContext($ctx)->whereKey($return->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isPrepared()) {
                throw new TaxException('Only a prepared (not yet submitted) return can be withdrawn.');
            }
            $locked->forceFill(['status' => 'draft', 'figures' => null, 'figures_hash' => null, 'prepared_at' => null, 'prepared_by_user_id' => null,
                'output_tax_cents' => null, 'input_tax_cents' => null, 'payable_cents' => null, 'checks' => null])->save();
            $this->linkDeadline($ctx, $locked, 'open');
            FinanceAudit::record($ctx->ownerAttributes(), 'withdrawn', 'tax_return', (int) $locked->id, $locked->kindLabel().' '.$locked->period_label, ['status' => ['before' => 'prepared', 'after' => 'draft']], mb_substr(trim($reason), 0, 200), null, $by->id);

            return $locked;
        });
    }

    public function recordSubmission(LedgerContext $ctx, TaxReturn $return, string $sarsReference, CarbonImmutable $submittedOn, User $by, ?int $proofAttachmentId = null, ?string $note = null, ?CarbonImmutable $today = null): TaxReturn
    {
        $today ??= CarbonImmutable::today();
        $reference = trim($sarsReference);
        if ($reference === '' || mb_strlen($reference) > 60) {
            throw new TaxException('Enter the reference SARS gave you when you submitted (up to 60 characters).');
        }
        if ($submittedOn->gt($today)) {
            throw new TaxException('The submission date cannot be in the future.');
        }

        return DB::transaction(function () use ($ctx, $return, $reference, $submittedOn, $by, $proofAttachmentId, $note) {
            $locked = TaxReturn::query()->forContext($ctx)->whereKey($return->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isPrepared()) {
                throw new TaxException($locked->isSubmitted() ? 'A submission is already recorded for this return and cannot change. Record a revision instead.' : 'Prepare the return first: only a prepared return can be recorded as submitted.');
            }
            if ($submittedOn->lt(CarbonImmutable::parse($locked->period_end->toDateString())->addDay())) {
                throw new TaxException('A return cannot be submitted before its period has ended.');
            }
            if (hash_equals((string) $locked->figures_hash, '') || ! $this->figuresIntact($locked)) {
                throw new TaxException('The frozen figures no longer match their hash. Withdraw and prepare the return again.');
            }

            $locked->forceFill([
                'status' => 'submitted', 'sars_reference' => $reference, 'submitted_on' => $submittedOn->toDateString(), 'submitted_by_user_id' => $by->id,
                'submission_proof_attachment_id' => $proofAttachmentId, 'submission_note' => $note !== null ? mb_substr(trim($note), 0, 500) : null,
            ])->save();

            $this->linkDeadline($ctx, $locked, 'submitted');
            FinanceAudit::record($ctx->ownerAttributes(), 'submission_recorded', 'tax_return', (int) $locked->id, $locked->kindLabel().' '.$locked->period_label, [
                'status' => ['before' => 'prepared', 'after' => 'submitted'], 'submitted_on' => ['after' => $submittedOn->toDateString()],
            ], 'reference '.$reference.' recorded by a person; not submitted by Dot.Finance', null, $by->id);

            return $locked;
        });
    }

    public function recordPayment(LedgerContext $ctx, TaxReturn $return, int $paidCents, CarbonImmutable $paidOn, ?string $reference, User $by, ?int $proofAttachmentId = null, ?CarbonImmutable $today = null): TaxReturn
    {
        $today ??= CarbonImmutable::today();
        if ($paidCents <= 0) {
            throw new TaxException('The payment must be more than zero.');
        }
        if ($paidOn->gt($today)) {
            throw new TaxException('The payment date cannot be in the future.');
        }

        return DB::transaction(function () use ($ctx, $return, $paidCents, $paidOn, $reference, $by, $proofAttachmentId) {
            $locked = TaxReturn::query()->forContext($ctx)->whereKey($return->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'submitted') {
                throw new TaxException($locked->status === 'paid' ? 'A payment is already recorded for this return.' : 'Record the submission first.');
            }
            if ((int) $locked->payable_cents <= 0) {
                throw new TaxException('This return shows nothing to pay (it is a refund or nil return).');
            }

            $locked->forceFill([
                'status' => 'paid', 'paid_cents' => $paidCents, 'paid_on' => $paidOn->toDateString(), 'payment_reference' => $reference !== null ? mb_substr(trim($reference), 0, 60) : null,
                'paid_by_user_id' => $by->id, 'payment_proof_attachment_id' => $proofAttachmentId,
            ])->save();

            $this->linkDeadline($ctx, $locked, 'paid');
            FinanceAudit::record($ctx->ownerAttributes(), 'payment_recorded', 'tax_return', (int) $locked->id, $locked->kindLabel().' '.$locked->period_label, [
                'paid_cents' => ['after' => $paidCents], 'payable_cents' => ['after' => $locked->payable_cents],
            ], 'payment recorded by a person', null, $by->id);

            return $locked;
        });
    }

    /** A correction after submission: a new draft revision that supersedes the submitted one. */
    public function startRevision(LedgerContext $ctx, TaxReturn $return, User $by): TaxReturn
    {
        return DB::transaction(function () use ($ctx, $return, $by) {
            $latest = $this->current($ctx, $return->kind, CarbonImmutable::parse($return->period_start->toDateString()), CarbonImmutable::parse($return->period_end->toDateString()));
            if ($latest === null || ! $latest->isSubmitted()) {
                throw new TaxException('A revision starts from a return recorded as submitted, and only one draft revision can exist.');
            }

            $revision = TaxReturn::query()->create($ctx->ownerAttributes() + [
                'kind' => $latest->kind, 'period_start' => $latest->period_start->toDateString(), 'period_end' => $latest->period_end->toDateString(),
                'period_label' => $latest->period_label, 'revision' => $latest->revision + 1, 'supersedes_return_id' => $latest->id,
                'status' => 'draft', 'basis' => $latest->basis, 'channel' => $latest->channel,
            ]);
            FinanceAudit::record($ctx->ownerAttributes(), 'revision_started', 'tax_return', (int) $revision->id, $revision->kindLabel().' '.$revision->period_label.' revision '.$revision->revision, ['supersedes' => ['after' => $latest->id]], null, null, $by->id);

            return $revision;
        });
    }

    // ── settlement journal (VAT), draft then confirm ────────────────────

    /**
     * The journal that moves the period's VAT out of the output and input accounts into VAT control (what is owed to SARS).
     * Built from the books, not from the return, so the output and input accounts end the period at zero.
     *
     * @return list<array{account: string, debit_cents: int, credit_cents: int, label: string}>
     */
    public function suggestSettlement(LedgerContext $ctx, TaxReturn $return): array
    {
        $from = $return->period_start->toDateString();
        $to = $return->period_end->toDateString();
        $out = $this->lines->creditNet($ctx, 'vat_output', $from, $to);
        $in = -$this->lines->creditNet($ctx, 'vat_input', $from, $to); // debit net
        $lines = [];

        if ($out !== 0) {
            $lines[] = ['account' => 'vat_output', 'debit_cents' => max($out, 0), 'credit_cents' => max(-$out, 0), 'label' => 'Clear output VAT for the period'];
        }
        if ($in !== 0) {
            $lines[] = ['account' => 'vat_input', 'debit_cents' => max(-$in, 0), 'credit_cents' => max($in, 0), 'label' => 'Clear input VAT for the period'];
        }
        $control = $out - $in; // credit to VAT control when VAT is owed
        if ($control !== 0) {
            $lines[] = ['account' => 'vat_control', 'debit_cents' => max(-$control, 0), 'credit_cents' => max($control, 0), 'label' => $control > 0 ? 'VAT owed to SARS' : 'VAT refundable from SARS'];
        }

        return $lines;
    }

    public function postSettlement(LedgerContext $ctx, TaxReturn $return, User $by): JournalEntry
    {
        if ($return->kind !== 'vat201') {
            throw new TaxException('A settlement journal is for a VAT201.');
        }
        if ($return->isDraft()) {
            throw new TaxException('Prepare the return before posting its settlement.');
        }
        if (! $this->sync->isEnabled($ctx)) {
            throw new TaxException('Accounting is not switched on.');
        }

        $lines = $this->suggestSettlement($ctx, $return);
        if ($lines === []) {
            throw new TaxException('No VAT moved through the VAT accounts in this period: there is nothing to settle.');
        }

        return LedgerContext::run($ctx, fn () => DB::transaction(function () use ($ctx, $return, $by, $lines) {
            $entry = $this->poster->post(new PostingRequest(
                CarbonImmutable::parse($return->period_end->toDateString()), 'adjustment', 'vat_settlement:'.$return->id, 1,
                'VAT settlement for '.$return->period_label.': output and input VAT moved to VAT control',
                array_map(fn ($l) => new PostingLine($l['account'], debitCents: $l['debit_cents'], creditCents: $l['credit_cents'], description: $l['label']), $lines),
                $by->id,
            ));
            TaxReturn::query()->whereKey($return->id)->update(['settlement_entry_id' => $entry->id]);
            FinanceAudit::record($ctx->ownerAttributes(), 'settlement_posted', 'tax_return', (int) $return->id, $return->kindLabel().' '.$return->period_label, ['journal_entry_id' => ['after' => $entry->id]], null, null, $by->id);

            return $entry;
        }));
    }

    // ── integrity ───────────────────────────────────────────────────────

    /** Whether the frozen figures still hash to the stored hash (tamper evidence). */
    public function figuresIntact(TaxReturn $return): bool
    {
        if ($return->figures === null || $return->figures_hash === null) {
            return false;
        }
        $f = $return->figures;
        $core = ['kind' => $f['kind'], 'period_start' => $f['period_start'], 'period_end' => $f['period_end'], 'boxes' => $f['boxes'],
            'output_tax_cents' => $f['output_tax_cents'], 'input_tax_cents' => $f['input_tax_cents'], 'payable_cents' => $f['payable_cents']];

        return hash_equals($return->figures_hash, hash('sha256', json_encode($core, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
    }

    private function linkDeadline(LedgerContext $ctx, TaxReturn $return, string $status): void
    {
        $deadline = TaxDeadline::query()->forContext($ctx)->where('obligation', $return->kind)
            ->where('period_start', $return->period_start->toDateString())->where('period_end', $return->period_end->toDateString())->first();
        if ($deadline === null) {
            return;
        }

        $deadline->forceFill(['tax_return_id' => $return->id, 'status' => $status]);
        match ($status) {
            'prepared' => $deadline->prepared_at = now(),
            'submitted' => $deadline->submitted_at = now(),
            'paid' => $deadline->paid_at = now(),
            default => null,
        };
        if ($status === 'open') {
            $deadline->forceFill(['prepared_at' => null, 'tax_return_id' => null]);
        }
        $deadline->save();
    }

    /** Keep the stored reconciliation small and stable: totals and reasons, not every line. */
    private function slimReconciliation(array $rec): array
    {
        unset($rec['review']);
        foreach ($rec['explained'] ?? [] as $i => $e) {
            $rec['explained'][$i]['lines'] = array_map(fn ($l) => ['line_id' => $l['line_id'], 'cents' => $l['cents']], $e['lines'] ?? []);
        }

        return $rec;
    }

    /** @return list<string> */
    private function categoryWarnings(LedgerContext $ctx, TaxReturn $return, TaxProfile $profile): array
    {
        $rules = app(TaxRules::class)->session($ctx);
        try {
            $period = app(VatPeriods::class)->containing($rules, CarbonImmutable::parse($return->period_start->toDateString()), $profile->vat_category, $profile->financial_year_end_month);
        } catch (\Throwable) {
            return [];
        }

        $matches = $period['start']->toDateString() === $return->period_start->toDateString() && $period['end']->toDateString() === $return->period_end->toDateString();

        return $matches ? [] : ["This period is not one of the category {$profile->vat_category} periods in the profile (expected {$period['start']->format('j M Y')} to {$period['end']->format('j M Y')}). Check the category."];
    }
}
