<?php

namespace App\Support\Audit;

use App\Models\FinanceAuditLog;
use App\Support\Money;
use Throwable;

/**
 * Turns one audit-log row into lines a person can read: a plain field name, the value with its unit, before and after when
 * both were recorded. It reads whatever shape a writer stored (a before/after pair, a "changed" flag, a bare value, a list)
 * and never throws: a row it cannot read degrades to a single line, so the page that lists the log always renders.
 *
 * Money is held as whole cents and shown in the entry's currency, so a field called payable_cents is shown as "Payable to
 * SARS" or "Payable", never "Payable cents". Fingerprints and other machine fields are separated into `working`.
 */
final class AuditEntryView
{
    /** Plain names for fields whose own name would read badly. */
    private const LABELS = [
        'account_id' => 'Account', 'cash_account_id' => 'Paid from account', 'category_id' => 'Category', 'recurring_rule_id' => 'Recurring rule',
        'amount_cents' => 'Amount', 'opening_balance_cents' => 'Opening balance', 'opening_balance_date' => 'Opening date',
        'payable_cents' => 'Payable', 'paye_cents' => 'PAYE', 'gross_pay_cents' => 'Gross pay', 'uif_employee_cents' => 'UIF (employee)',
        'uif_employer_cents' => 'UIF (employer)', 'sdl_cents' => 'SDL', 'tax_cents' => 'Tax', 'claimable_tax_cents' => 'Tax you can claim',
        'total_cents' => 'Total', 'base_total_cents' => 'Total in your main currency', 'limit_cents' => 'Limit', 'threshold_cents' => 'Approval threshold',
        'credit_limit_cents' => 'Credit limit', 'fx_diff_cents' => 'Exchange difference', 'variance_cents' => 'Difference', 'in_cents' => 'Money in',
        'out_cents' => 'Money out', 'cleared_cents' => 'Cleared', 'closing_cents' => 'Closing balance', 'written_off_cents' => 'Written off',
        'min_cents' => 'Smallest amount', 'max_cents' => 'Largest amount', 'fx_rate_e8' => 'Exchange rate', 'is_active' => 'Active',
        'transfer_direction' => 'Transfer direction', 'transfer_group' => 'Transfer', 'recurrence_type' => 'Repeats', 'recurrence_interval' => 'Every',
        'next_occurrence_date' => 'Next occurrence', 'end_date' => 'Ends', 'start_date' => 'Starts', 'starts_on' => 'Starts', 'ends_on' => 'Ends',
        'paused_at' => 'Paused', 'ended_at' => 'Ended', 'next_run_on' => 'Next run', 'due_by' => 'Pay by', 'paid_on' => 'Paid on',
        'gl_entry_id' => 'Journal entry', 'journal_entry_id' => 'Journal entry', 'closing_entry_id' => 'Closing entry', 'transaction_id' => 'Transaction',
        'payment_id' => 'Payment', 'party_id' => 'Customer or supplier', 'member_user_id' => 'Member', 'claimant_user_id' => 'Claimant',
        'subject_type' => 'Applies to', 'document_type' => 'Document', 'vat_basis' => 'VAT basis', 'vat_registered' => 'VAT registered',
        'books_closed_through' => 'Books closed through', 'accounting_enabled_at' => 'Accounting switched on', 'retention_until' => 'Keep until',
        'allow_self_approval' => 'May approve their own', 'self_approved' => 'Approved by the person who asked', 'fiscal_year' => 'Financial year',
        'financial_year_end_month' => 'Financial year ends in month', 'early_payment_discount_bp' => 'Early payment discount', 'early_payment_days' => 'Early payment days',
        'allocatable_type' => 'Applied to', 'allocatable_id' => 'Applied to', 'verified_by_callback' => 'Confirmed by the provider', 'steps_required' => 'Approvals needed',
        'steps_done' => 'Approvals given', 'payment_terms_days' => 'Payment terms (days)', 'rows_total' => 'Rows read', 'rows_reconciled' => 'Rows reconciled',
        'already_held' => 'Already held', 'min_role' => 'Lowest role', 'approver_capability' => 'Who may approve', 'legal_entity' => 'Legal entity',
        'supplier_reference' => 'Supplier reference', 'member_count' => 'Members', 'supplier_count' => 'Suppliers', 'external_ref' => 'External reference',
    ];

    /** Fields that are machine fingerprints, kept out of the main line. */
    private const WORKING = ['figures_hash', 'entry_hash', 'prev_hash', 'rule_versions', 'idempotency_key', 'file_hash', 'fingerprint'];

    /** @var array<string, array<int, string>> */
    private array $names;

    /** @param array<string, array<int, string>> $names account_id / category_id => id => name */
    public function __construct(array $names = [])
    {
        $this->names = $names;
    }

    /**
     * Every account and category id this entry mentions, so the controller can look their names up once for the page.
     *
     * @return array{account_id: list<int>, category_id: list<int>}
     */
    public static function referencedIds(FinanceAuditLog $entry): array
    {
        $out = ['account_id' => [], 'category_id' => []];
        $changes = $entry->changes;
        if (! is_array($changes)) {
            return $out;
        }
        foreach (array_keys($out) as $field) {
            $value = $changes[$field] ?? null;
            $candidates = is_array($value) ? [$value['before'] ?? null, $value['after'] ?? null] : [$value];
            foreach ($candidates as $c) {
                if (is_int($c) || (is_string($c) && ctype_digit($c))) {
                    $out[$field][] = (int) $c;
                }
            }
        }

        return $out;
    }

    /**
     * @return array{lines: list<array{label: string, text: string, before: ?string, after: ?string, kind: string}>, working: list<array{label: string, text: string}>, unreadable: bool}
     */
    public function present(FinanceAuditLog $entry): array
    {
        try {
            return $this->build($entry);
        } catch (Throwable) {
            return ['lines' => [], 'working' => [], 'unreadable' => true];
        }
    }

    /** @return array{lines: list<array<string, mixed>>, working: list<array{label: string, text: string}>, unreadable: bool} */
    private function build(FinanceAuditLog $entry): array
    {
        $changes = $entry->changes;
        if (! is_array($changes)) {
            return ['lines' => [], 'working' => [], 'unreadable' => $changes !== null && $changes !== ''];
        }

        $currency = is_string($entry->currency ?? null) ? $entry->currency : (is_string($changes['currency'] ?? null) ? $changes['currency'] : null);
        $lines = [];
        $working = [];

        foreach ($changes as $field => $change) {
            $field = (string) $field;
            if (in_array($field, self::WORKING, true) || str_ends_with($field, '_hash')) {
                $working[] = ['label' => self::label($field), 'text' => $this->scalar($field, is_array($change) ? ($change['after'] ?? $change['before'] ?? null) : $change, $currency)];

                continue;
            }

            $line = ['label' => self::label($field), 'before' => null, 'after' => null, 'text' => '', 'kind' => 'value'];

            if (is_array($change) && array_key_exists('changed', $change)) {
                $line['kind'] = 'changed';
                $line['text'] = 'changed';
            } elseif (is_array($change) && (array_key_exists('before', $change) || array_key_exists('after', $change))) {
                $line['before'] = array_key_exists('before', $change) ? $this->scalar($field, $change['before'], $currency) : null;
                $line['after'] = array_key_exists('after', $change) ? $this->scalar($field, $change['after'], $currency) : null;
                $line['kind'] = $line['before'] !== null && $line['after'] !== null ? 'pair' : ($line['after'] !== null ? 'set' : 'was');
                $line['text'] = (string) ($line['after'] ?? $line['before']);
            } else {
                // a bare value (an import tally, a recorded figure) or a list
                $line['kind'] = 'set';
                $line['text'] = $this->scalar($field, $change, $currency);
                $line['after'] = $line['text'];
            }

            $lines[] = $line;
        }

        return ['lines' => $lines, 'working' => $working, 'unreadable' => false];
    }

    /** A plain name for a field: the known name, else the field's own words without its storage suffix. */
    public static function label(string $field): string
    {
        if (isset(self::LABELS[$field])) {
            return self::LABELS[$field];
        }

        $base = preg_replace('/_(cents|e8|bp|id|ids)$/', '', $field) ?? $field;
        $base = preg_replace('/_at$/', '', $base) ?? $base;
        $words = trim(str_replace('_', ' ', $base));

        return $words === '' ? $field : ucfirst($words);
    }

    private function scalar(string $field, mixed $value, ?string $currency): string
    {
        if ($value === null) {
            return '—';
        }
        if (isset($this->names[$field]) && (is_int($value) || (is_string($value) && ctype_digit($value)))) {
            return $this->names[$field][(int) $value] ?? 'record #'.$value;
        }
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if (is_array($value)) {
            return $this->listText($value);
        }
        if (! is_scalar($value)) {
            return '—';
        }

        if (str_ends_with($field, '_cents') && (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value)))) {
            return Money::format((int) $value, $currency);
        }
        if (str_ends_with($field, '_bp') && is_numeric($value)) {
            return rtrim(rtrim(number_format(((int) $value) / 100, 2, '.', ''), '0'), '.').'%';
        }
        if (str_ends_with($field, '_e8') && is_numeric($value)) {
            return rtrim(rtrim(number_format(((int) $value) / 100_000_000, 8, '.', ''), '0'), '.');
        }
        if (in_array($field, ['is_active', 'vat_registered', 'allow_self_approval', 'self_approved', 'verified_by_callback', 'replaces_active'], true)) {
            return in_array($value, [1, '1', true, 'true'], true) ? 'yes' : 'no';
        }

        $text = (string) $value;

        return preg_match('/^[a-z]+(_[a-z]+)+$/', $text) === 1 ? str_replace('_', ' ', $text) : $text;
    }

    /** A short readable summary of a list or map value: its size, never raw JSON. */
    private function listText(array $value): string
    {
        $count = count($value);

        return $count === 0 ? 'none' : ($count === 1 ? '1 item' : $count.' items');
    }
}
