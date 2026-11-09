<?php

namespace App\Services\Tax;

use App\Models\Shared\Party;
use App\Models\Tax\TaxReviewResolution;
use App\Models\User;
use App\Services\Tax\Dto\ComputedReturn;
use App\Support\FinanceAudit;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The VAT review queue: the things in a period's books that a person should look at before the return is prepared.
 * Errors block the return until each is fixed in the books or accepted with a note; warnings are shown and kept.
 *
 *   untagged_vat_line                   error    a line on a VAT account that carries no tax code (uncategorised)
 *   standard_without_vat                error    a standard-rated sale with no VAT line
 *   zero_rated_with_vat                 error    VAT on a zero-rated or exempt line (zero vs standard conflict)
 *   vat_amount_mismatch                 error    booked output VAT differs from the rate by more than rounding
 *   denied_input_claimed                error    input VAT under a code that does not allow a claim
 *   code_not_for_sales                  error    a purchase-only code used on a sale
 *   input_vat_no_supplier_vat_number    warning  input VAT whose supplier has no VAT number on file
 *   input_vat_no_document               warning  input VAT whose source record has no file attached
 *   input_vat_old                       warning  input VAT older than the 5-year claim window
 *   (others from the builder)           warning  see Vat201Builder
 */
final class VatReviewQueue
{
    public function __construct(
        private readonly GlTaxLines $lines,
        private readonly SupportingDocuments $documents,
        private readonly TaxRules $rules,
    ) {}

    /**
     * @return list<array<string, mixed>> each issue plus 'resolved' (bool) and 'resolution' (array|null)
     */
    public function forPeriod(LedgerContext $ctx, CarbonImmutable $from, CarbonImmutable $to, ComputedReturn $booksOnly): array
    {
        $issues = $booksOnly->issues;
        $glLines = $this->lines->vatAccountLines($ctx, $from->toDateString(), $to->toDateString());

        // uncategorised: a VAT-account line with no tax code
        foreach ($glLines as $l) {
            if ($l->tax_code_id === null) {
                $issues[] = [
                    'code' => 'untagged_vat_line', 'severity' => 'error', 'journal_line_id' => (int) $l->line_id, 'entry_id' => (int) $l->entry_id,
                    'entry_number' => (int) $l->entry_number, 'date' => $l->entry_date, 'amount_cents' => (int) ($l->credit_cents - $l->debit_cents),
                    'message' => 'A line on the VAT '.($l->system_key === 'vat_output' ? 'output' : 'input').' account has no tax code, so it is in no box.',
                    'hint' => 'Give the entry a tax code (the entries are append-only: post a corrected entry), or accept it with a note if it is meant to stay out.',
                ];
            }
        }

        // input VAT used in the return: supplier and document checks
        $inputLines = $glLines->where('system_key', 'vat_input')->filter(fn ($l) => $l->tax_code_id !== null && $l->debit_cents > $l->credit_cents);
        $usedInInput = [];
        foreach ($booksOnly->boxes as $box) {
            if (in_array($box['code'], ['14', '14A', '15', '15A'], true)) {
                foreach ($box['contributors'] as $c) {
                    if ($c['type'] === 'line') {
                        $usedInInput[$c['id']] = true;
                    }
                }
            }
        }
        $inputLines = $inputLines->filter(fn ($l) => isset($usedInInput[$l->line_id]))->values();

        if ($inputLines->isNotEmpty()) {
            $parties = Party::query()->forContext($ctx)->whereIn('id', $inputLines->pluck('party_id')->filter()->unique()->all())->get()->keyBy('id');
            $docs = $this->documents->forEntries($ctx, $inputLines->map(fn ($l) => (object) ['entry_id' => $l->entry_id, 'source_type' => $l->source_type, 'source_id' => $l->source_id]));
            $windowYears = $this->rules->session($ctx)->rule('vat.input_claim_window_years', $to)->integer();

            foreach ($inputLines as $l) {
                $base = ['journal_line_id' => (int) $l->line_id, 'entry_id' => (int) $l->entry_id, 'entry_number' => (int) $l->entry_number, 'date' => $l->entry_date, 'amount_cents' => (int) ($l->debit_cents - $l->credit_cents)];
                $party = $l->party_id ? $parties->get($l->party_id) : null;

                if ($party === null) {
                    $issues[] = $base + ['code' => 'input_vat_no_supplier_vat_number', 'severity' => 'warning', 'message' => 'Input VAT with no supplier recorded: a claim needs the supplier\'s name and VAT number on a valid tax invoice.', 'hint' => 'Record the supplier on the bill or transaction.'];
                } elseif (blank($party->tax_number)) {
                    $issues[] = $base + ['code' => 'input_vat_no_supplier_vat_number', 'severity' => 'warning', 'message' => "Input VAT from {$party->name}, who has no VAT number on file.", 'hint' => 'Add the VAT number to the supplier, from their tax invoice. If they are not a VAT vendor, no input VAT can be claimed.'];
                }

                if (! ($docs[(int) $l->entry_id] ?? false)) {
                    $issues[] = $base + ['code' => 'input_vat_no_document', 'severity' => 'warning', 'message' => 'Input VAT with no supporting file attached (the tax invoice).', 'hint' => 'Attach the supplier\'s tax invoice. A claim needs a valid one.'];
                }

                if (CarbonImmutable::parse($l->entry_date)->lt($to->subYears($windowYears))) {
                    $issues[] = $base + ['code' => 'input_vat_old', 'severity' => 'warning', 'message' => "Input VAT dated {$l->entry_date} is older than the {$windowYears}-year claim window.", 'hint' => 'A claim must be made within 5 years of the period in which the invoice should have been issued.'];
                }
            }
        }

        return $this->withResolutions($ctx, $issues);
    }

    /** Record a person's decision on one flagged line. */
    public function resolve(LedgerContext $ctx, int $journalLineId, string $issueCode, string $resolution, string $note, User $by): TaxReviewResolution
    {
        return DB::transaction(function () use ($ctx, $journalLineId, $issueCode, $resolution, $note, $by) {
            $existing = TaxReviewResolution::query()->forContext($ctx)->where('journal_line_id', $journalLineId)->where('issue_code', $issueCode)->first();
            if ($existing) {
                return $existing;
            }

            $row = TaxReviewResolution::query()->create($ctx->ownerAttributes() + [
                'journal_line_id' => $journalLineId, 'issue_code' => $issueCode, 'resolution' => $resolution,
                'note' => $note, 'resolved_by_user_id' => $by->id,
            ]);

            FinanceAudit::record($ctx->ownerAttributes(), 'resolved', 'tax_review', (int) $row->id, 'VAT review: '.$issueCode, [
                'journal_line_id' => ['after' => $journalLineId], 'resolution' => ['after' => $resolution],
            ], null, null, $by->id);

            return $row;
        });
    }

    /** @param  list<array<string, mixed>>  $issues */
    private function withResolutions(LedgerContext $ctx, array $issues): array
    {
        $ids = collect($issues)->pluck('journal_line_id')->filter()->unique()->all();
        $rows = $ids === [] ? collect() : TaxReviewResolution::query()->forContext($ctx)->whereIn('journal_line_id', $ids)->get();
        $byKey = $rows->keyBy(fn ($r) => $r->journal_line_id.'|'.$r->issue_code);

        return array_map(function (array $issue) use ($byKey) {
            $res = $byKey->get(($issue['journal_line_id'] ?? 0).'|'.$issue['code']);
            $issue['resolved'] = $res !== null;
            $issue['resolution'] = $res ? ['resolution' => $res->resolution, 'note' => $res->note, 'at' => $res->created_at?->toDateTimeString()] : null;

            return $issue;
        }, $issues);
    }
}
