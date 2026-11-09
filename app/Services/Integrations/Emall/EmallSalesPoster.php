<?php

namespace App\Services\Integrations\Emall;

use App\Enums\ApprovalSubject;
use App\Enums\Capability;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalEntry;
use App\Models\Integrations\EmallSaleLine;
use App\Models\Shared\TaxCode;
use App\Models\User;
use App\Services\Accounting\Chart;
use App\Services\Accounting\Exceptions\AccountingNotReady;
use App\Services\Accounting\Exceptions\PeriodClosed;
use App\Services\Accounting\GlSync;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\PostingLine;
use App\Services\Accounting\PostingRequest;
use App\Services\Controls\ApprovalService;
use App\Support\FinanceAudit;
use App\Support\Ledger\LedgerAccess;
use App\Support\Ledger\LedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Posts one reviewed Dot.Emall sale to the books, by a person with the journal capability, through the one poster.
 *
 *   Dr  Receivable from Dot.Emall (gross sale minus the marketplace fee: what Emall owes the seller)
 *   Dr  Fee expense               (the marketplace fee)
 *   Cr  Sales                     (net of VAT)
 *   Cr  VAT output                (when the sale carries VAT)
 *
 * Only a "staged" line can be posted (a line with a problem must be rejected or fixed at the source), only in the
 * ledger's base currency (no exchange rate is invented), only when accounting is on and the amount does not need
 * approval under the ledger's journal policy. Posting is idempotent: the journal's source is "emall:{order_item_id}".
 */
final class EmallSalesPoster
{
    public function __construct(private readonly JournalPoster $poster, private readonly Chart $chart, private readonly GlSync $gl) {}

    public function post(EmallSaleLine $line, User $by, ?int $feeAccountId = null): JournalEntry
    {
        $context = $line->ledgerContext();

        if (! LedgerAccess::allows(Capability::PostJournal, $by, $context)) {
            throw new AuthorizationException('You may not post journals in this ledger.');
        }

        return LedgerContext::run($context, function () use ($line, $by, $feeAccountId, $context) {
            return DB::transaction(function () use ($line, $by, $feeAccountId, $context) {
                $locked = EmallSaleLine::query()->forContext($context)->lockForUpdate()->findOrFail($line->id);

                if ($locked->status === 'posted' && $locked->journal_entry_id !== null) {
                    return JournalEntry::query()->forContext($context)->findOrFail($locked->journal_entry_id);
                }

                if ($locked->status !== 'staged') {
                    throw ValidationException::withMessages(['line' => 'Only a line without problems can be posted.']);
                }

                $settings = $this->gl->settings($context);
                if ($settings === null || ! $settings->isEnabled()) {
                    throw ValidationException::withMessages(['line' => 'Switch accounting on for this ledger before posting sales to it.']);
                }

                if ($locked->currency !== $settings->base_currency) {
                    throw ValidationException::withMessages(['line' => "This sale is in {$locked->currency} and the books are in {$settings->base_currency}. No exchange rate is guessed: record it by hand."]);
                }

                if (ApprovalService::requiresApproval(ApprovalSubject::Journal->value, $locked->gross_cents, $locked->currency, $context)) {
                    throw ValidationException::withMessages(['line' => 'This amount needs approval under your journal approval rules. Record it as a manual journal and send that for approval.']);
                }

                $feeAccount = null;
                if ($locked->fee_cents > 0) {
                    $feeAccount = $feeAccountId !== null
                        ? GlAccount::query()->forContext($context)->where('class', 'expense')->where('is_active', true)->where('is_postable', true)->find($feeAccountId)
                        : $this->chart->system('cost_of_sales', $context);
                    if ($feeAccount === null) {
                        throw ValidationException::withMessages(['fee_account_id' => 'Choose an active expense account for the marketplace fee.']);
                    }
                }

                $taxCodeId = $locked->tax_code ? TaxCode::query()->where('code', $locked->tax_code)->value('id') : null;
                $owed = $locked->gross_cents - $locked->fee_cents;
                $label = "Dot.Emall {$locked->order_ref}";

                $lines = array_values(array_filter([
                    $owed > 0 ? new PostingLine('ar', debitCents: $owed, description: "{$label}: receivable from Dot.Emall") : null,
                    $feeAccount ? new PostingLine($feeAccount->id, debitCents: $locked->fee_cents, description: "{$label}: marketplace fee") : null,
                    $locked->netCents() > 0 ? new PostingLine('sales', creditCents: $locked->netCents(), description: $locked->description) : null,
                    $locked->vat_cents > 0 ? new PostingLine('vat_output', creditCents: $locked->vat_cents, taxCodeId: $taxCodeId ? (int) $taxCodeId : null, description: "{$label}: VAT") : null,
                ]));

                try {
                    $entry = $this->poster->post(new PostingRequest(
                        CarbonImmutable::parse($locked->sold_on->toDateString()),
                        'import_batch',
                        'emall:'.$locked->order_item_id,
                        1,
                        mb_substr("{$label}: {$locked->description}", 0, 500),
                        $lines,
                        $by->id,
                    ));
                } catch (PeriodClosed $e) {
                    throw ValidationException::withMessages(['line' => $e->getMessage()]);
                } catch (AccountingNotReady $e) {
                    throw ValidationException::withMessages(['line' => $e->getMessage()]);
                }

                $locked->forceFill(['status' => 'posted', 'journal_entry_id' => $entry->id, 'reviewed_by' => $by->id, 'reviewed_at' => now()])->save();
                FinanceAudit::record($context->ownerAttributes(), 'posted', 'emall_sale', $locked->id, $locked->auditLabel(), ['journal_entry_id' => ['after' => $entry->id]], null, $locked->currency, $by->id);

                return $entry;
            });
        });
    }

    public function reject(EmallSaleLine $line, User $by): EmallSaleLine
    {
        $context = $line->ledgerContext();

        if (! LedgerAccess::allows(Capability::PostJournal, $by, $context)) {
            throw new AuthorizationException('You may not review sales in this ledger.');
        }

        return DB::transaction(function () use ($line, $by, $context) {
            $locked = EmallSaleLine::query()->forContext($context)->lockForUpdate()->findOrFail($line->id);

            if (in_array($locked->status, ['staged', 'needs_attention'], true)) {
                $locked->forceFill(['status' => 'rejected', 'reviewed_by' => $by->id, 'reviewed_at' => now()])->save();
                FinanceAudit::record($context->ownerAttributes(), 'rejected', 'emall_sale', $locked->id, $locked->auditLabel(), null, null, $locked->currency, $by->id);
            }

            return $locked;
        });
    }
}
