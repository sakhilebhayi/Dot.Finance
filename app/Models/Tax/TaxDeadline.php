<?php

namespace App\Models\Tax;

use App\Casts\DateOnly;
use App\Models\Concerns\HasLedgerContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One dated obligation in the tax calendar (a VAT201 for a period, an EMP201, a provisional payment...). Generated from
 * the rules; the stored status moves only when a person prepares, records a submission or records a payment. Whether it
 * is upcoming, due or overdue is worked out from the dates (displayStatus()).
 */
class TaxDeadline extends Model
{
    use HasLedgerContext;

    public const OBLIGATIONS = [
        'vat201' => 'VAT return (VAT201)',
        'emp201' => 'Monthly employer tax return (EMP201)',
        'irp6_1' => 'Provisional tax, first payment (IRP6)',
        'irp6_2' => 'Provisional tax, second payment (IRP6)',
        'irp6_3' => 'Provisional tax, third (top-up) payment (IRP6)',
        'itr14' => 'Company income tax return (ITR14)',
        'itr12' => 'Personal income tax return (ITR12)',
        'emp501_interim' => 'Employer tax reconciliation, half-year (EMP501)',
        'emp501_annual' => 'Employer tax reconciliation, full year (EMP501)',
        'coida_roe' => 'Workers\' compensation return of earnings (COIDA)',
    ];

    /** Days before the due date at which a deadline is "due" (shown as needing attention). */
    public const DUE_SOON_DAYS = 14;

    protected $fillable = [
        'user_id', 'team_id', 'obligation', 'period_start', 'period_end', 'period_label', 'base_due_date', 'due_date',
        'payment_due_date', 'adjustment_note', 'status', 'tax_return_id', 'prepared_at', 'submitted_at', 'paid_at',
        'rule_versions', 'confidence', 'waived_reason', 'generated_at',
    ];

    protected $casts = [
        'period_start' => DateOnly::class,
        'period_end' => DateOnly::class,
        'base_due_date' => DateOnly::class,
        'due_date' => DateOnly::class,
        'payment_due_date' => DateOnly::class,
        'prepared_at' => 'datetime',
        'submitted_at' => 'datetime',
        'paid_at' => 'datetime',
        'generated_at' => 'datetime',
        'rule_versions' => 'array',
    ];

    public function ledgerReferences(): array
    {
        return ['tax_return_id' => TaxReturn::class];
    }

    public function taxReturn(): BelongsTo
    {
        return $this->belongsTo(TaxReturn::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(TaxReminderNotice::class, 'tax_deadline_id');
    }

    public function label(): string
    {
        return self::OBLIGATIONS[$this->obligation] ?? $this->obligation;
    }

    /** upcoming | due | prepared | submitted | paid | overdue | waived */
    public function displayStatus(?CarbonImmutable $today = null): string
    {
        if (in_array($this->status, ['submitted', 'paid', 'waived'], true)) {
            return $this->status;
        }

        $today ??= CarbonImmutable::today();
        $due = CarbonImmutable::parse($this->due_date->toDateString());

        if ($today->gt($due)) {
            return 'overdue';
        }

        if ($this->status === 'prepared') {
            return 'prepared';
        }

        return $today->gte($due->subDays(self::DUE_SOON_DAYS)) ? 'due' : 'upcoming';
    }

    /** Needs a payment (a return, EMP201 or provisional payment), as opposed to a filing only. */
    public function carriesPayment(): bool
    {
        return in_array($this->obligation, ['vat201', 'emp201', 'irp6_1', 'irp6_2', 'irp6_3'], true);
    }

    /** The period in plain words (the stored label may carry a SARS category letter). */
    public function periodText(): string
    {
        return \App\Services\Tax\TaxLabels::plainPeriod($this->period_label, $this->period_start, $this->period_end);
    }
}
