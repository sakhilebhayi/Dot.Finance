<?php

namespace App\Models\Tax;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;

/** A public holiday of a jurisdiction (global reference data, no owner). verified = false means derived, not from a published list. */
class TaxHoliday extends Model
{
    protected $fillable = ['jurisdiction', 'holiday_date', 'name', 'source', 'verified', 'note'];

    protected $casts = ['holiday_date' => DateOnly::class, 'verified' => 'boolean'];
}
