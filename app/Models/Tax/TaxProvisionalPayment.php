<?php

namespace App\Models\Tax;

use App\Casts\DateOnly;
use App\Models\Concerns\HasLedgerContext;
use Illuminate\Database\Eloquent\Model;

/** One provisional-tax period of a tax year: the person's own estimate, the tax it implies, and the payment they record. */
class TaxProvisionalPayment extends Model
{
    use HasLedgerContext;

    protected $fillable = [
        'user_id', 'team_id', 'tax_year_end', 'period', 'due_date', 'estimate_taxable_income_cents', 'basic_amount_cents',
        'basic_amount_year_end', 'tax_on_estimate_cents', 'amount_due_cents', 'paid_cents', 'paid_on', 'payment_reference',
        'updated_by_user_id',
    ];

    protected $casts = [
        'tax_year_end' => DateOnly::class, 'due_date' => DateOnly::class, 'basic_amount_year_end' => DateOnly::class, 'paid_on' => DateOnly::class,
        'period' => 'integer', 'estimate_taxable_income_cents' => 'integer', 'basic_amount_cents' => 'integer',
        'tax_on_estimate_cents' => 'integer', 'amount_due_cents' => 'integer', 'paid_cents' => 'integer',
    ];
}
