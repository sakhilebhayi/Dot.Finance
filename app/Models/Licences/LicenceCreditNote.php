<?php

namespace App\Models\Licences;

use Illuminate\Database\Eloquent\Model;

class LicenceCreditNote extends Model
{
    protected $table = 'licence_credit_notes';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }
}
